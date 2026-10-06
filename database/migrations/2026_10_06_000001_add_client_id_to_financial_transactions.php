<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->unique()->after('id');
        });
    }
    public function down(): void {
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
