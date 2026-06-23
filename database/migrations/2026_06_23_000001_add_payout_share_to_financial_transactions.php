<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documents 'payout_share' as a valid financial_transaction type.
        // Adds FK columns linking payout FTs back to their distribution snapshot and shareholder.
        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->foreignId('distribution_snapshot_id')
                ->nullable()
                ->after('payroll_record_id')
                ->constrained('distribution_snapshots')
                ->nullOnDelete();

            $table->foreignId('shareholder_id')
                ->nullable()
                ->after('distribution_snapshot_id')
                ->constrained('shareholders')
                ->nullOnDelete();
        });

        // Add paid_at / paid_by to snapshots for quick payout status queries.
        Schema::table('distribution_snapshots', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('created_by');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('distribution_snapshots', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['paid_at', 'paid_by']);
        });

        Schema::table('financial_transactions', function (Blueprint $table) {
            $table->dropForeign(['distribution_snapshot_id']);
            $table->dropForeign(['shareholder_id']);
            $table->dropColumn(['distribution_snapshot_id', 'shareholder_id']);
        });
    }
};
