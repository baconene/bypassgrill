<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('order_items', fn (Blueprint $table) => $table->timestamp('inventory_costed_at')->nullable());
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('inventory_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->string('source', 32);
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingredient_name')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('total_cost', 14, 2);
            $table->foreignId('financial_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('inventory_cost_entries')->restrictOnDelete();
            $table->dateTime('recognized_at');
            $table->timestamps();
            $table->index(['kind', 'recognized_at']);
        });
        Schema::create('cogs_ledger_settings', function (Blueprint $table) {
            $table->id();
            $table->dateTime('shadow_started_at');
            $table->dateTime('cogs_ledger_start_at')->nullable();
        });
        DB::table('cogs_ledger_settings')->insert(['id' => 1, 'shadow_started_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cogs_ledger_settings');
        Schema::dropIfExists('inventory_cost_entries');
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('inventory_costed_at'));
        Schema::table('ingredients', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
