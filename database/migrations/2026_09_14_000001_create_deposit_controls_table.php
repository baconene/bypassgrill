<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // One shared POS ledger: only one shift may be open or awaiting counts.
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->json('opening_snapshot');
            $table->json('closing_snapshot')->nullable();
            $table->json('reconciliation')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_controls');
    }
};
