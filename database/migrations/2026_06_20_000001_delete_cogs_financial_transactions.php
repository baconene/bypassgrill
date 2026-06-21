<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('financial_transactions')
            ->where('type', 'expense')
            ->where('description', 'like', 'COGS:%')
            ->delete();
    }

    public function down(): void
    {
        // COGS records are no longer created; this deletion is not reversible
    }
};
