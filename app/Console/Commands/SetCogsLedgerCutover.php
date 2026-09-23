<?php

namespace App\Console\Commands;

use App\Enums\InventoryCostKind;
use App\Models\InventoryCostEntry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetCogsLedgerCutover extends Command
{
    protected $signature = 'cogs:cutover {--at= : When the ledger takes over (YYYY-MM-DD, default today)} {--undo : Return to shadow mode}';

    protected $description = 'Hand COGS over to the inventory cost ledger from a given date, or undo that';

    public function handle(): int
    {
        $settings = DB::table('cogs_ledger_settings')->first();

        if (! $settings) {
            $this->error('The ledger has not been installed yet. Run migrations first.');

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => null]);
            $this->info('Back in shadow mode. Reports use the recorded order-item costs again.');

            return self::SUCCESS;
        }

        try {
            $at = $this->option('at')
                ? Carbon::createFromFormat('Y-m-d', $this->option('at'))->startOfDay()
                : Carbon::today();
        } catch (\Throwable) {
            $this->error('Use a YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $shadowStart = Carbon::parse($settings->shadow_started_at);

        if ($at->lt($shadowStart)) {
            $this->error('The cutover cannot predate the shadow ledger, which started '.$shadowStart->toDateString().'.');
            $this->line('Orders before that have no ledger entries, so their COGS would read as zero.');

            return self::FAILURE;
        }

        $consumption = InventoryCostEntry::whereIn('kind', [
            InventoryCostKind::CONSUMPTION->value,
            InventoryCostKind::CONSUMPTION_REVERSAL->value,
        ])->where('recognized_at', '>=', $at)->count();

        if ($consumption === 0 && ! $this->option('no-interaction')) {
            $this->warn('No consumption entries recorded on or after '.$at->toDateString().'.');

            if (! $this->confirm('COGS will read as zero for orders after the cutover. Continue?', false)) {
                return self::FAILURE;
            }
        }

        DB::table('cogs_ledger_settings')->update(['cogs_ledger_start_at' => $at]);

        $this->info('COGS now comes from the ledger for orders created on or after '.$at->toDateString().'.');
        $this->line('Orders before that keep the cost recorded on their items.');
        $this->line('Run php artisan cogs:cutover --undo to go back.');

        return self::SUCCESS;
    }
}
