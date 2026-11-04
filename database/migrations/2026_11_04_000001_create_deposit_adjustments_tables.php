<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each step is guarded so a run that failed part-way on MySQL (DDL is not transactional) can simply be re-run.
        // A request to balance a shift's overall over/short. It is posted to the ledger only after three admins approve.
        if (! Schema::hasTable('deposit_adjustments')) {
            Schema::create('deposit_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deposit_control_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                // Signed overall variance at request time: positive = over, negative = short
                $table->decimal('amount', 12, 2);
                $table->text('reason');
                // pending -> approved -> posted, or rejected / cancelled / superseded (a newer shift started first)
                $table->string('status', 20)->default('pending')->index();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('payment_tender_id')->nullable()->constrained('payment_tenders')->nullOnDelete();
                $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->nullOnDelete();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('deposit_adjustment_approvals')) {
            Schema::create('deposit_adjustment_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deposit_adjustment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->timestamp('approved_at');
                $table->timestamps();
            });
        }

        // One approval per admin per request. Named explicitly: the generated name is longer than MySQL's 64 characters.
        if (! Schema::hasIndex('deposit_adjustment_approvals', 'dep_adj_approvals_adjustment_user_unique')) {
            Schema::table('deposit_adjustment_approvals', function (Blueprint $table) {
                $table->unique(['deposit_adjustment_id', 'user_id'], 'dep_adj_approvals_adjustment_user_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_adjustment_approvals');
        Schema::dropIfExists('deposit_adjustments');
    }
};
