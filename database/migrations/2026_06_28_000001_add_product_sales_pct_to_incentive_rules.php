<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE incentive_rules MODIFY COLUMN pool_type ENUM('gross_sales_pct','gross_profit_pct','net_profit_pct','fixed_amount','product_sales_pct') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE incentive_rules MODIFY COLUMN pool_type ENUM('gross_sales_pct','gross_profit_pct','net_profit_pct','fixed_amount') NOT NULL");
    }
};
