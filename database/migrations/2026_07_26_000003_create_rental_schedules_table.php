<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stall_id')->constrained('rental_stalls')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('rental_tenants')->cascadeOnDelete();
            $table->enum('rental_type', ['daily', 'weekly', 'monthly', 'custom'])->default('daily');
            $table->enum('status', ['reserved', 'confirmed', 'cancelled', 'maintenance'])->default('reserved');
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stall_id', 'start_date', 'end_date']);
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_schedules');
    }
};
