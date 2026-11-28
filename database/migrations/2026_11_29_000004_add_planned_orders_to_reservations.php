<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // dine_in | takeout | delivery; carried into the order once the reservation is paid
            $table->string('order_type', 20)->nullable();
            $table->string('customer_address', 500)->nullable();
            // staff (saved in the dashboard or the app) | online (sent from the homepage)
            $table->string('source', 20)->default('staff');
            // Set only once the reservation is paid: no order exists before that
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
        });

        // The planned order: what the customer wants, without touching stock or sales
        Schema::create('reservation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_items');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['order_type', 'customer_address', 'source', 'paid_at']);
        });
    }
};
