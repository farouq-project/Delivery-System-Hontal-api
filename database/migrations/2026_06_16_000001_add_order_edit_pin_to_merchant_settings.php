<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->string('order_edit_pin', 10)->default('152')->after('klotter_size');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('order_edit_pin');
        });
    }
};
