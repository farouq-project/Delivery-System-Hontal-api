<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('cashier_name', 50)->nullable()->after('created_by');
            $table->enum('payment_method', ['cash', 'transfer', 'qris'])->nullable()->after('cashier_name');

            $table->index(['merchant_id', 'cashier_name']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'cashier_name']);
            $table->dropColumn(['cashier_name', 'payment_method']);
        });
    }
};
