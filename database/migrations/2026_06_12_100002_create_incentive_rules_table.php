<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incentive_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('pool_type', ['gross_sales_pct', 'gross_profit_pct', 'net_profit_pct', 'fixed_amount']);
            $table->decimal('rate', 10, 4);
            $table->enum('distribution_method', ['by_sales', 'equal'])->default('by_sales');
            $table->boolean('is_active')->default(true);
            $table->date('effective_date');
            $table->date('expiration_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incentive_rules');
    }
};
