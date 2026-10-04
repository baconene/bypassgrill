<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('print_service_settings',function(Blueprint $table){$table->string('print_bluetooth_name')->default('')->after('print_channel');$table->string('print_bluetooth_address')->default('')->after('print_bluetooth_name');});}public function down():void{Schema::table('print_service_settings',function(Blueprint $table){$table->dropColumn(['print_bluetooth_name','print_bluetooth_address']);});}};
