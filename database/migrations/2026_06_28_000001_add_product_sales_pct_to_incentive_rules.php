<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->poolTypes(['gross_sales_pct', 'gross_profit_pct', 'net_profit_pct', 'fixed_amount', 'product_sales_pct']);
    }

    public function down(): void
    {
        $this->poolTypes(['gross_sales_pct', 'gross_profit_pct', 'net_profit_pct', 'fixed_amount']);
    }

    private function poolTypes(array $types): void
    {
        // MODIFY is MySQL-only; other drivers (the SQLite test database) use a portable column change.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE incentive_rules MODIFY COLUMN pool_type ENUM('".implode("','", $types)."') NOT NULL");

            return;
        }

        Schema::table('incentive_rules', fn (Blueprint $table) => $table->enum('pool_type', $types)->change());
    }
};
