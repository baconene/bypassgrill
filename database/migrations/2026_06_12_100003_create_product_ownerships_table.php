<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ownerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shareholder_id')->constrained()->cascadeOnDelete();
            $table->decimal('ownership_percentage', 5, 2);
            $table->timestamps();
            $table->unique(['product_id', 'shareholder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ownerships');
    }
};
