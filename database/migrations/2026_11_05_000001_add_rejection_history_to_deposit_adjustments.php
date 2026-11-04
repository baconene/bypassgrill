<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A request can now be rejected, reopened and rejected again, so keep every rejection
        if (! Schema::hasColumn('deposit_adjustments', 'rejection_history')) {
            Schema::table('deposit_adjustments', function (Blueprint $table) {
                $table->json('rejection_history')->nullable()->after('rejection_reason');
            });
        }

        // Carry over rejections made before this column existed
        DB::table('deposit_adjustments')->whereNotNull('rejected_by')->whereNull('rejection_history')->orderBy('id')
            ->each(function ($row) {
                $name = DB::table('users')->where('id', $row->rejected_by)->value('name');
                DB::table('deposit_adjustments')->where('id', $row->id)->update(['rejection_history' => json_encode([[
                    'user_id' => $row->rejected_by, 'name' => $name, 'reason' => $row->rejection_reason, 'at' => $row->rejected_at,
                ]])]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('deposit_adjustments', 'rejection_history')) {
            Schema::table('deposit_adjustments', fn (Blueprint $table) => $table->dropColumn('rejection_history'));
        }
    }
};
