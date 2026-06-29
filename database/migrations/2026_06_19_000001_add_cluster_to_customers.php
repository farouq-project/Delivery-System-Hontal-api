<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('cluster', 50)->nullable()->after('vip_level');
            $table->index(['merchant_id', 'cluster']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'cluster']);
            $table->dropColumn('cluster');
        });
    }
};
