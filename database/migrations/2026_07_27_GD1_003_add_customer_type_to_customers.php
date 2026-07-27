<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type', 100)->nullable()->after('cluster');
            $table->index(['merchant_id', 'customer_type']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'customer_type']);
            $table->dropColumn('customer_type');
        });
    }
};
