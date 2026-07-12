<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

class ClearKitchenMonitor extends Command
{
    protected $signature = 'kitchen:clear
                            {--except=* : Order IDs to skip (can be repeated or comma-separated)}
                            {--dry-run : Preview what would be completed without making changes}';

    protected $description = 'Mark all active kitchen monitor orders (pending/preparing/ready) as completed';

    public function handle(OrderService $orderService): int
    {
        $exceptIds = $this->parseExceptIds();

        $orders = Order::whereIn('status', [
            OrderStatus::PENDING->value,
            OrderStatus::PREPARING->value,
            OrderStatus::READY->value,
        ])->orderBy('id')->get();

        $toProcess = $orders->filter(fn($o) => ! in_array($o->id, $exceptIds));
        $skipped   = $orders->filter(fn($o) =>   in_array($o->id, $exceptIds));

        $this->info("Active kitchen orders found : {$orders->count()}");
        $this->info("Skipping                    : " . $skipped->map(fn($o) => "#{$o->id}")->join(', '));
        $this->info("Will complete               : {$toProcess->count()}");

        if ($toProcess->isEmpty()) {
            $this->warn('Nothing to complete.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['ID', 'Status', 'Customer', 'Total'], $toProcess->map(fn($o) => [
                $o->id, $o->status, $o->customer_name ?? '—', number_format($o->total_amount, 2),
            ]));
            $this->warn('[Dry-run] No changes made.');
            return self::SUCCESS;
        }

        if (! $this->confirm("Complete {$toProcess->count()} order(s) now?", true)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $completed = 0;
        $failed    = 0;

        foreach ($toProcess as $order) {
            try {
                $orderService->updateOrderStatus($order, OrderStatus::COMPLETED);
                $this->line("  ✓ Order #{$order->id} ({$order->status} → completed)");
                $completed++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Order #{$order->id} failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Completed: {$completed}" . ($failed ? ", Failed: {$failed}" : '') . '.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function parseExceptIds(): array
    {
        $raw = $this->option('except');
        $ids = [];
        foreach ($raw as $val) {
            foreach (explode(',', $val) as $id) {
                $trimmed = trim($id);
                if (is_numeric($trimmed)) {
                    $ids[] = (int) $trimmed;
                }
            }
        }
        return $ids;
    }
}
