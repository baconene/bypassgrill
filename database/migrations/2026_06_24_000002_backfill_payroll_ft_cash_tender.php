<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cashTender = DB::table('payment_tenders')
            ->whereRaw('LOWER(name) = ?', ['cash'])
            ->value('id');

        if ($cashTender === null) {
            return;
        }

        DB::table('financial_transactions')
            ->where('type', 'payroll')
            ->whereNull('payment_tender_id')
            ->update(['payment_tender_id' => $cashTender]);
    }

    public function down(): void
    {
        // Intentionally left blank — data cleanup is not reversible
    }
};
