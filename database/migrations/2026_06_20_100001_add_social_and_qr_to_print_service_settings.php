<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('print_service_settings', function (Blueprint $table) {
            $table->string('social_facebook')->nullable()->after('print_footer');
            $table->string('social_instagram')->nullable()->after('social_facebook');
            $table->string('receipt_qr_type')->default('order_url')->after('social_instagram');
        });
    }

    public function down(): void
    {
        Schema::table('print_service_settings', function (Blueprint $table) {
            $table->dropColumn(['social_facebook', 'social_instagram', 'receipt_qr_type']);
        });
    }
};
