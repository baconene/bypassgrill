<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'asset_deduction' as a valid type for financial transactions.
        // No schema change needed since type is string(50).
        // This migration documents the change and can be used for data updates if needed.
    }

    public function down(): void
    {
        // Rollback: revert any asset_deduction records if migration included data changes.
        // Currently this is a documentation migration only.
    }
};
