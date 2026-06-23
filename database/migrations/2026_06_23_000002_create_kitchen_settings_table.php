<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('serving_fast_minutes')->default(5);
            $table->unsignedTinyInteger('serving_slow_minutes')->default(10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_settings');
    }
};
