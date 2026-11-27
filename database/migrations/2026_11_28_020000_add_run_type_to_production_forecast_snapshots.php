<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            $table->string('run_type', 8)->default('live');
        });

        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            $table->dropUnique(['forecast_as_of', 'service_date']);
            $table->unique(['run_type', 'forecast_as_of', 'service_date'], 'production_forecast_run_date_unique');
        });
    }

    public function down(): void
    {
        DB::table('production_forecast_snapshots')->where('run_type', 'replay')->delete();
        Schema::table('production_forecast_snapshots', function (Blueprint $table) {
            $table->dropUnique('production_forecast_run_date_unique');
            $table->unique(['forecast_as_of', 'service_date']);
        });
        Schema::table('production_forecast_snapshots', fn (Blueprint $table) => $table->dropColumn('run_type'));
    }
};
