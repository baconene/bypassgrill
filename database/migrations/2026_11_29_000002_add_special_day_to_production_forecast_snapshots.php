<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            // payday_long_weekend | long_weekend | holiday | near_payday, or null for a regular day
            $table->string('special_day', 24)->nullable();
            // Learned sales multiplier applied for that kind of day (1 = no change)
            $table->decimal('uplift', 6, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            $table->dropColumn(['special_day', 'uplift']);
        });
    }
};
