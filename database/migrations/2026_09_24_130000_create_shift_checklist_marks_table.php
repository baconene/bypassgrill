<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only for steps the system cannot prove on its own, such as a stock check
        // that changed nothing. The deposit control steps are read from the shift.
        Schema::create('shift_checklist_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('step', 32);
            $table->timestamps();
            $table->unique(['user_id', 'business_date', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_checklist_marks');
    }
};
