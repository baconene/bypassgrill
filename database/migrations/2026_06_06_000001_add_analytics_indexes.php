<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Range + status scans for every analytics query
            if (! $this->indexExists('orders', 'orders_status_created_at_index')) {
                $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Affinity self-join and product-by-hour aggregation
            if (! $this->indexExists('order_items', 'order_items_order_product_index')) {
                $table->index(['order_id', 'product_id'], 'order_items_order_product_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_product_index');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        // Driver-agnostic, so the migration also runs on the SQLite test database.
        return Schema::hasIndex($table, $index);
    }
};
