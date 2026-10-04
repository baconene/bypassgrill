<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_pos_tokens', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable(); $table->timestamps();
        });
        Schema::create('mobile_pos_sync_records', function (Blueprint $table) {
            $table->id(); $table->string('client_id', 100)->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mobile_pos_sync_records');
        Schema::dropIfExists('mobile_pos_tokens');
    }
};
