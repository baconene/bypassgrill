<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hris_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_tender_id')
                ->nullable()
                ->constrained('payment_tenders')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hris_settings');
    }
};
