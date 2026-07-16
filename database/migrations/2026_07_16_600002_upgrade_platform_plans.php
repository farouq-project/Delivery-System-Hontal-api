<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(14)->after('display_order');
            $table->unsignedInteger('customer_limit')->nullable()->after('driver_limit');
            $table->softDeletes(); // archive support
        });
    }

    public function down(): void
    {
        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['trial_days', 'customer_limit']);
        });
    }
};
