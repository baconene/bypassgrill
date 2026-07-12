<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('id');
        });

        // Backfill existing orders
        $appKey = config('app.key');

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) use ($appKey) {
            foreach ($orders as $order) {
                $raw   = $order->id . '|' . ($order->customer_name ?? '') . '|' . $order->created_at;
                $token = substr(hash_hmac('sha256', $raw, $appKey), 0, 32);

                DB::table('orders')->where('id', $order->id)->update(['public_token' => $token]);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
