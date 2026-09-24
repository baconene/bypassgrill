<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_catalog_imports', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('before_snapshot');
            $table->json('summary');
            $table->timestamp('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_catalog_imports');
    }
};
