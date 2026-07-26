<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            // Business identity — drives configurable labels and future dashboard modules
            $table->string('business_type', 100)->nullable()->after('merchant_id');
            $table->string('business_unit', 50)->default('order')->after('business_type');
            $table->string('business_category', 100)->nullable()->after('business_unit');
            $table->string('operating_region', 100)->nullable()->after('business_category');
            $table->char('currency', 3)->default('IDR')->after('operating_region');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'business_unit', 'business_category', 'operating_region', 'currency']);
        });
    }
};
