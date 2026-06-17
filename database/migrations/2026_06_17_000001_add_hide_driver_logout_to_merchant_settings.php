<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->boolean('hide_driver_logout')->default(false)->after('order_edit_pin');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('hide_driver_logout');
        });
    }
};
