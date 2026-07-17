<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('location_validation_radius')->default(30)->after('hide_driver_logout');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('location_validation_radius');
        });
    }
};
