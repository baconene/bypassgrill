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
                            {--dry-run : Preview without writing}';

    protected $description = 'One-time balance realignment: cash income adjustment + GCash expense to match physical counts (Sep 21 2026)';

    public function handle(): int
    {
        $adjustments = [
            [
                'tender_name' => 'Cash',
                'type'        => 'income_adjustment',
                'amount'      => 1928.58,
                'description' => 'Balance realignment – cash count 2026-09-21',
            ],
            [
                'tender_name' => 'GCash',
                'type'        => 'expense',
                'amount'      => 2216.63,
                'description' => 'Balance realignment – GCash count 2026-09-21',
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

        $this->info('Done. Net effect on running balance: −₱288.05');

        return self::SUCCESS;
    }
}
