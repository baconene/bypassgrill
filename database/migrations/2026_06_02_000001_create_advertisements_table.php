<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['banner', 'promo', 'announcement'])->default('banner');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('badge_text', 50)->nullable();
            $table->string('bg_color', 20)->default('orange');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisements');
    }
};
