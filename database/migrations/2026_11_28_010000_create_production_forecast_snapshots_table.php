<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('forecast_as_of');
            $table->date('service_date')->index();
            $table->dateTime('captured_at');
            $table->decimal('estimated_net_sales', 12, 2)->nullable();
            $table->decimal('weekday_baseline', 12, 2)->nullable();
            $table->string('model_version', 30);
            $table->boolean('used_context');
            $table->unsignedSmallInteger('sample_days');
            $table->unsignedTinyInteger('payday_distance');
            $table->string('weather_source', 16);
            $table->string('forecast_condition', 12)->nullable();
            $table->decimal('rain_chance', 5, 2)->nullable();
            $table->unsignedTinyInteger('tolerance_pct');
            $table->decimal('actual_net_sales', 12, 2)->nullable();
            $table->decimal('sales_error', 12, 2)->nullable();
            $table->decimal('baseline_error', 12, 2)->nullable();
            $table->decimal('observed_rain_mm', 8, 2)->nullable();
            $table->string('observed_condition', 12)->nullable();
            $table->string('result', 16)->default('pending');
            $table->dateTime('evaluated_at')->nullable();
            $table->unique(['forecast_as_of', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_forecast_snapshots');
    }
};
