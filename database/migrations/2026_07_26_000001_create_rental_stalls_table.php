<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_stalls', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('number')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('rental_stalls')->insert([
            ['number' => 1, 'label' => 'Stall 1', 'description' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['number' => 2, 'label' => 'Stall 2', 'description' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['number' => 3, 'label' => 'Stall 3', 'description' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['number' => 4, 'label' => 'Stall 4', 'description' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['number' => 5, 'label' => 'Stall 5', 'description' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_stalls');
    }
};
