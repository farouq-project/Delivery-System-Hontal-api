<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('location_change_warning_radius')->default(2)->after('location_validation_radius');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('location_change_warning_radius');
        });
    }
};
