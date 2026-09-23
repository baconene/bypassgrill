<?php

namespace App\Console\Commands;

use App\Models\InventoryCostEntry;
use App\Models\InventoryTransaction;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyCogsLedger extends Command
{
    protected $signature = 'cogs:verify {--from= : First movement date (YYYY-MM-DD)} {--to= : Last movement date (YYYY-MM-DD)}';

    protected $description = 'Read-only shadow COGS coverage and item-cost comparison; never changes stock or cash';

    public function handle(): int
    {
        $start = DB::table('cogs_ledger_settings')->value('shadow_started_at');
        try {
            foreach (['from', 'to'] as $option) {
                if ($this->option($option) && ! Carbon::canBeCreatedFromFormat($this->option($option), 'Y-m-d')) {
                    throw new \InvalidArgumentException('Use YYYY-MM-DD dates.');
                }
            }
            $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : Carbon::parse($start);
            $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : now();
            if ($from > $to) {
                throw new \InvalidArgumentException('End must be on or after start.');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $entries = InventoryCostEntry::whereBetween('recognized_at', [$from, $to]);
        $missing = InventoryTransaction::whereBetween('created_at', [$from, $to])->where('created_at', '>=', $start)
            ->whereNotNull('order_item_id')->whereIn('type', ['stock_in', 'stock_out'])
            ->whereNotIn('id', InventoryCostEntry::whereNotNull('inventory_transaction_id')->select('inventory_transaction_id'))->count();
        $uncosted = OrderItem::where('created_at', '>=', $start)->whereBetween('created_at', [$from, $to])
            ->whereNull('inventory_costed_at')->where('status', '!=', 'cancelled')->count();
        $differences = 0;
        OrderItem::whereBetween('inventory_costed_at', [$from, $to])->where('status', '!=', 'cancelled')->chunkById(200, function ($items) use (&$differences) {
            foreach ($items as $item) {
                $ledger = (float) InventoryCostEntry::where('order_item_id', $item->id)->sum('total_cost');
                if (round($ledger * 100) !== round((float) $item->cost_subtotal * 100)) {
                    $differences++;
                }
            }
        });
        $totals = (clone $entries)->selectRaw('kind, source, COUNT(*) as entries, SUM(total_cost) as cost')->groupBy('kind', 'source')->get();
        $this->info('SHADOW MODE: production reports still use recorded order-item costs.');
        $this->table(['Kind', 'Source', 'Entries', 'Signed cost'], $totals->map(fn ($r) => [$r->kind->value, $r->source->value, $r->entries, number_format((float) $r->cost, 2)])->all());
        $this->line("Missing movement links: {$missing}; uncosted new items: {$uncosted}");
        $this->line("Item cost differences versus current report: {$differences} (expected with stale product costs; review before cutover)");

        return $missing || $uncosted ? self::FAILURE : self::SUCCESS;
    }
}
