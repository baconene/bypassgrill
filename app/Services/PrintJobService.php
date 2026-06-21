<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\PrintServiceSetting;
use Pusher\PushNotifications\PushNotifications;
use Pusher\Pusher;

class PrintJobService
{
    public function queueForOrder(Order $order): PrintJob
    {
        return $this->createAndDeliver($order, 'manual', null);
    }

    /** @deprecated Use queueForOrder() — kept for backwards compat */
    public function queueForNewOrder(Order $order): PrintJob
    {
        return $this->createAndDeliver($order, 'new_order', null);
    }

    /** @deprecated Use queueForOrder() — kept for backwards compat */
    public function queueForStatusChange(Order $order, string $newStatus): PrintJob
    {
        return $this->createAndDeliver($order, 'status_change', $newStatus);
    }

    private function createAndDeliver(Order $order, string $trigger, ?string $triggerStatus): PrintJob
    {
        $order->loadMissing(['items.product', 'user', 'queueNumber', 'payments.tender']);

        // Channel: column value, then cached fallback (set when the column is
        // unavailable), then default. Works whether or not the migration ran.
        $setting        = PrintServiceSetting::getSetting();
        $channel        = ($setting->getAttribute('print_channel') ?: \Illuminate\Support\Facades\Cache::get('print_service_channel')) ?: 'orders';
        $receiptPayload = $this->buildAndroidReceipt($order);

        // Build the tracking record in memory first, but DO NOT let a missing
        // print_jobs table block the actual printing — persist best-effort below.
        $job = new PrintJob([
            'order_id'       => $order->id,
            'trigger'        => $trigger === 'manual' ? 'new_order' : $trigger,
            'trigger_status' => $triggerStatus,
            'status'         => 'pending',
            'receipt_data'   => $receiptPayload,
            'attempts'       => 1,
        ]);

        $delivered = false;

        // ── Pusher Channels — direct SDK (the actual print; independent of the DB) ──
        try {
            $key    = config('broadcasting.connections.pusher.key');
            $secret = config('broadcasting.connections.pusher.secret');
            $appId  = config('broadcasting.connections.pusher.app_id');

            if ($key && $secret && $appId) {
                $pusher = new Pusher($key, $secret, $appId, [
                    'cluster' => config('broadcasting.connections.pusher.options.cluster', 'ap1'),
                    'useTLS'  => true,
                ]);

                // Single event only — Android binds to both names, so sending
                // both would cause the receipt to print twice.
                $pusher->trigger($channel, 'print', ['receipt' => $receiptPayload]);

                $delivered = true;
            }
        } catch (\Throwable) { /* fall through to Beams */ }

        // ── Pusher Beams — FCM wake-up ping ──────────────────────────────────────────
        try {
            $instanceId = config('broadcasting.beams.instance_id');
            $secretKey  = config('broadcasting.beams.secret_key');

            if ($instanceId && $secretKey) {
                $beams = new PushNotifications([
                    'instanceId' => $instanceId,
                    'secretKey'  => $secretKey,
                ]);

                $beams->publishToInterests(['print-jobs'], [
                    'fcm' => [
                        'notification' => [
                            'title' => "Order #{$order->id} — Print Receipt",
                            'body'  => $this->notificationBody($order),
                        ],
                        'data' => ['print_job_id' => (string) ($job->id ?? '')],
                    ],
                ]);

                $delivered = true;
            }
        } catch (\Throwable) { /* non-critical */ }

        // Persist the tracking record — never block printing on a write failure
        // (e.g. print_jobs table not migrated yet).
        $job->status        = $delivered ? 'sent' : 'failed';
        $job->sent_at       = $delivered ? now() : null;
        $job->failed_reason = $delivered ? null : 'No delivery channel configured.';
        try {
            $job->save();
        } catch (\Throwable) { /* table missing / write failed — printing already happened */ }

        return $job;
    }

    private function buildAndroidReceipt(Order $order): array
    {
        $settings = PrintServiceSetting::getSetting();

        // Get the most recent completed payment
        $payment        = $order->payments->where('status', 'completed')->first()
                       ?? $order->payments->sortByDesc('created_at')->first();
        $amountTendered = (float) ($payment?->amount ?? $order->total_amount);
        $change         = max(0.0, $amountTendered - (float) $order->total_amount);
        $paymentMethod  = $payment?->tender?->name ?? $payment?->method ?? null;

        return [
            'store' => [
                'name'    => $settings->print_store_name ?: config('app.name'),
                'address' => $settings->print_store_address,
                'phone'   => $settings->print_store_phone,
                'footer'  => $settings->print_footer ?: 'Thank you for dining with us!',
            ],
            'order' => [
                'number'   => (string) $order->id,
                'type'     => $this->formatOrderType($order->order_type),
                'table'    => $order->table_number,
                'date'     => $order->created_at?->setTimezone('Asia/Manila')->format('M d, Y h:i A'),
                'cashier'  => $order->user?->name,
                'customer' => $order->customer_name,
                'contact'  => $order->customer_contact,
                'address'  => $order->customer_address,
            ],
            'items' => $order->items->map(fn ($item) => [
                'name'  => $item->product?->name ?? 'Unknown',
                'qty'   => (float) $item->quantity,
                'price' => (float) $item->unit_price,
                'total' => (float) $item->subtotal,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount_amount,
                'tax'      => (float) $order->tax_amount,
                'total'    => (float) $order->total_amount,
            ],
            'payment' => $payment ? [
                'method' => $paymentMethod,
                'amount' => $amountTendered,
                'change' => $change,
            ] : null,
            'qr_url' => $this->resolveQrUrl($settings, $order),
        ];
    }

    private function resolveQrUrl(PrintServiceSetting $settings, Order $order): ?string
    {
        return match ($settings->receipt_qr_type ?? 'order_url') {
            'none'      => null,
            'facebook'  => $settings->social_facebook ?: null,
            default     => rtrim(config('app.url'), '/') . '/public/orders/' . $order->id,
        };
    }

    private function formatOrderType(string $type): string
    {
        return match ($type) {
            'dine_in'  => 'Dine In',
            'takeout'  => 'Takeout',
            'delivery' => 'Delivery',
            default    => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function notificationBody(Order $order): string
    {
        $itemCount = $order->items->count();
        $total     = number_format((float) $order->total_amount, 2);
        return "{$itemCount} item(s) · ₱{$total}"
            . ($order->table_number ? " · Table {$order->table_number}" : '');
    }
}
