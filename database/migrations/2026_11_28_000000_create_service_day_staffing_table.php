<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_day_staffing', function (Blueprint $table) {
            $table->date('service_date')->primary();
            $table->unsignedSmallInteger('headcount');
            $table->decimal('staff_hours', 7, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_day_staffing');
    }
};
