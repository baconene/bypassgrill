<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily counter the Android POS draws blocks of offline order numbers from (restarts at 1 each day)
        if (! Schema::hasTable('mobile_pos_daily_counters')) {
            Schema::create('mobile_pos_daily_counters', function (Blueprint $table) {
                $table->date('counter_date')->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        // Number printed on the receipt while the order was offline, e.g. "O-012"
        if (! Schema::hasColumn('orders', 'offline_number')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('offline_number', 20)->nullable()->after('public_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'offline_number')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('offline_number'));
        }
        Schema::dropIfExists('mobile_pos_daily_counters');
    }
};
