<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PrintServiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class PrintServiceController extends Controller
{
    private const CHANNEL_CACHE_KEY = 'print_service_channel';

    public function getSettings(): JsonResponse
    {
        $this->adminOnly();

        $setting = PrintServiceSetting::getSetting();
        $payload = $setting->toArray();
        $payload['print_channel'] = $this->currentChannel($setting);

        return response()->json($payload);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->adminOnly();

        $data = $request->validate([
            'print_service_url'   => 'required|string|max:255',
            'print_paper_width'   => 'required|integer|in:32,48',
            'print_store_name'    => 'nullable|string|max:255',
            'print_store_address' => 'nullable|string|max:255',
            'print_store_phone'   => 'nullable|string|max:100',
            'print_footer'        => 'nullable|string|max:255',
            'print_auto_print'    => 'boolean',
            'print_enabled'       => 'boolean',
            'print_channel'       => 'nullable|string|max:100|regex:/^[A-Za-z0-9._-]+$/',
            'social_facebook'     => 'nullable|string|max:255',
            'social_instagram'    => 'nullable|string|max:255',
            'receipt_qr_type'     => 'nullable|string|in:none,facebook,order_url',
        ]);

        $channel = ($data['print_channel'] ?? '') ?: 'orders';
        unset($data['print_channel']);   // keep the core update independent of the column

        // These columns are NOT NULL in the DB; empty inputs arrive as null
        // (ConvertEmptyStringsToNull middleware) — coalesce to '' to avoid a
        // 1048 "Column cannot be null" integrity violation.
        foreach (['print_store_name', 'print_store_address', 'print_store_phone', 'print_footer'] as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        $data['receipt_qr_type'] = $data['receipt_qr_type'] ?? 'order_url';

        try {
            // Core settings — these columns always exist, so this can never fail on schema.
            $setting = PrintServiceSetting::getSetting();
            $setting->update($data);

            // Channel: persist to the column if available, otherwise cache it.
            $this->persistChannel($setting, $channel);

            $fresh = $setting->fresh();
            $payload = $fresh->toArray();
            $payload['print_channel'] = $this->currentChannel($fresh);

            return response()->json($payload);
        } catch (\Throwable $e) {
            // Surface the real cause even when APP_DEBUG=false (prod hides it otherwise).
            \Illuminate\Support\Facades\Log::error('Print settings save failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Save failed: ' . $e->getMessage(),
                'type'    => class_basename($e),
            ], 500);
        }
    }

    /** Persist the channel without ever 500-ing, even if the column/ALTER is unavailable. */
    private function persistChannel(PrintServiceSetting $setting, string $channel): void
    {
        // Best-effort: create the column if missing and we have privilege.
        try {
            if (! Schema::hasColumn('print_service_settings', 'print_channel')) {
                Schema::table('print_service_settings', function ($table) {
                    $table->string('print_channel')->default('orders');
                });
            }
        } catch (\Throwable) { /* no ALTER privilege — fall back to cache */ }

        try {
            if (Schema::hasColumn('print_service_settings', 'print_channel')) {
                $setting->forceFill(['print_channel' => $channel])->save();
                Cache::forever(self::CHANNEL_CACHE_KEY, $channel);  // mirror for hot-path reads
                return;
            }
        } catch (\Throwable) { /* column write failed — fall back to cache */ }

        Cache::forever(self::CHANNEL_CACHE_KEY, $channel);
    }

    /** Resolve the active channel from the column, then cache, then default. */
    private function currentChannel(PrintServiceSetting $setting): string
    {
        $fromColumn = $setting->getAttribute('print_channel');
        return ($fromColumn ?: Cache::get(self::CHANNEL_CACHE_KEY)) ?: 'orders';
    }

    public function testConnection(Request $request): JsonResponse
    {
        $this->adminOnly();

        $request->validate([
            'url' => 'required|string|max:255',
        ]);

        try {
            $response = Http::timeout(5)->get(
                rtrim($request->input('url'), '/') . '/status'
            );

            if ($response->successful()) {
                $body = $response->json();
                return response()->json([
                    'reachable'         => true,
                    'printer_connected' => $body['printer_connected'] ?? null,
                    'status'            => $body['status'] ?? null,
                ]);
            }

            return response()->json([
                'reachable' => false,
                'error'     => 'HTTP ' . $response->status(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'reachable' => false,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function adminOnly(): void
    {
        if (! auth()->user()?->hasAnyRole('admin')) {
            abort(403, 'Admin only');
        }
    }
}
