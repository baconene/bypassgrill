<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            // Likely sales range: where the middle 60% of past days landed around a forecast like this one
            $table->decimal('range_low', 12, 2)->nullable();
            $table->decimal('range_high', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            $table->dropColumn(['range_low', 'range_high']);
        });
    }
};
