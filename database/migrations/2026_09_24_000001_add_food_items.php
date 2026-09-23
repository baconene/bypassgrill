<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // item_type becomes a plain string. It was an enum of four values and now needs
        // a fifth; every later one would mean another table rebuild for nothing.
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('item_type', 32)->default('ingredient')->change();
        });

        // A recipe row now belongs to a product or to a food, never both and never
        // neither. product_id keeps its meaning, so $product->recipes is untouched.
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->foreignId('food_id')->nullable()->after('product_id')
                ->constrained('ingredients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('food_id');
        });

        // Rows orphaned by dropping food_id would violate the restored NOT NULL.
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->enum('item_type', ['ingredient', 'tool', 'equipment', 'supply'])
                ->default('ingredient')->change();
        });
    }
};
