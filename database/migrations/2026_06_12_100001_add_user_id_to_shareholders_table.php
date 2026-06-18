<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('shareholders', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class);
        });
    }
};
