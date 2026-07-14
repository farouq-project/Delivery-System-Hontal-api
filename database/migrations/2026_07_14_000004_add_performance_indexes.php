<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cashier summary report: merchant_id + cashier_name + order_created_at
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->index(['merchant_id', 'order_created_at'], 'idx_orders_merchant_created');
            $table->index(['merchant_id', 'cashier_name'],     'idx_orders_merchant_cashier');
        });

        // Product suggestions: merchant_id + usage_count DESC
        // Table is 'product_catalog' (non-standard name — no plural)
        Schema::table('product_catalog', function (Blueprint $table) {
            $table->index(['merchant_id', 'usage_count'], 'idx_catalog_merchant_usage');
        });

        // Customer listing correlated subquery performance:
        // delivery_orders.customer_id is already FK-indexed by MySQL (FK implies index),
        // but an explicit composite index improves subquery speed.
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->index(['customer_id', 'status'], 'idx_orders_customer_status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_merchant_created');
            $table->dropIndex('idx_orders_merchant_cashier');
            $table->dropIndex('idx_orders_customer_status');
        });

        Schema::table('product_catalog', function (Blueprint $table) {
            $table->dropIndex('idx_catalog_merchant_usage');
        });
    }
};
