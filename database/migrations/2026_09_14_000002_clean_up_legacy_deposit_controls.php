<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Runs once through Laravel's migration registry during deployment.
        // Preserve records created by the new workflow even if this is rerun.
        DB::transaction(function () {
            DB::table('deposit_controls')->select(['id', 'opening_snapshot'])
                ->orderBy('id')->chunkById(200, function ($rows) {
                    $legacyIds = $rows->filter(function ($row) {
                        $snapshot = json_decode($row->opening_snapshot, true, 512, JSON_THROW_ON_ERROR);

                        return ($snapshot['version'] ?? 1) < 2;
                    })->pluck('id');
                    DB::table('deposit_controls')->whereIn('id', $legacyIds)->delete();
                });
        });
    }

    public function down(): void
    {
        // Data cleanup is irreversible; rollback must not delete new records.
    }
};
