<?php

namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use App\Models\PaymentTender;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RealignBalance extends Command
{
    protected $signature = 'balance:realign
                            {--dry-run : Preview without writing}
                            {--revert : Delete the two realignment entries created on Sep 22}';

    protected $description = 'One-time balance realignment: cash income adjustment + GCash expense to match physical counts (Sep 21 2026)';

    public function handle(): int
    {
        if ($this->option('revert')) {
            return $this->revert();
        }

        $adjustments = [
            [
                'tender_name' => 'Cash',
                'type'        => 'expense',
                'amount'      => 288.05,
                'description' => 'Cash short – Sep 21 2026',
            ],
        ];

        $this->table(
            ['Tender', 'Type', 'Amount', 'Description'],
            array_map(fn ($a) => [$a['tender_name'], $a['type'], number_format($a['amount'], 2), $a['description']], $adjustments),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — no records written.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Create these entries?', false)) {
            return self::SUCCESS;
        }

        $admin = User::role('admin')->orderBy('id')->firstOrFail();

        DB::transaction(function () use ($adjustments, $admin) {
            foreach ($adjustments as $adj) {
                $tender = PaymentTender::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($adj['tender_name']) . '%'])->first();

                if (! $tender) {
                    $this->warn("Tender '{$adj['tender_name']}' not found — skipping.");
                    continue;
                }

                $tx = FinancialTransaction::create([
                    'type'              => $adj['type'],
                    'amount'            => $adj['amount'],
                    'description'       => $adj['description'],
                    'payment_tender_id' => $tender->id,
                    'transacted_at'     => now(),
                    'user_id'           => $admin->id,
                ]);

                $sign = $adj['type'] === 'income_adjustment' ? '+' : '-';
                $this->line(" ✓ #{$tx->id}  {$sign}₱" . number_format($adj['amount'], 2) . "  [{$tender->name}]  {$adj['description']}");
            }
        });

        $this->info('Done. Cash expense −₱288.05 recorded.');

        return self::SUCCESS;
    }

    private function revert(): int
    {
        $descriptions = [
            'Balance realignment – cash count 2026-09-21',
            'Balance realignment – GCash count 2026-09-21',
        ];

        $entries = FinancialTransaction::whereIn('description', $descriptions)->get();

        if ($entries->isEmpty()) {
            $this->warn('No realignment entries found — nothing to revert.');
            return self::SUCCESS;
        }

        $this->table(['ID', 'Type', 'Amount', 'Description'], $entries->map(fn ($e) => [
            $e->id, $e->type, number_format($e->amount, 2), $e->description,
        ])->toArray());

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing deleted.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Delete these entries?', false)) {
            return self::SUCCESS;
        }

        DB::transaction(fn () => $entries->each->delete());

        $this->info("{$entries->count()} entr" . ($entries->count() === 1 ? 'y' : 'ies') . ' deleted.');

        return self::SUCCESS;
    }
}
