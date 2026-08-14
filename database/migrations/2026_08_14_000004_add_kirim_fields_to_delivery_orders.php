<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            // Auto-set at order creation from merchant's feature flag — never a UI toggle
            $table->enum('delivery_type', ['merchant_managed', 'hontal_kirim'])
                ->default('merchant_managed')
                ->after('status');

            // Required for hontal_kirim orders (application-enforced)
            $table->foreignId('depot_id')
                ->nullable()
                ->after('delivery_type')
                ->constrained('depots')
                ->nullOnDelete();

            // Assigned at creation — null for merchant_managed orders
            $table->foreignId('batch_id')
                ->nullable()
                ->after('depot_id')
                ->constrained('hontal_kirim_batches')
                ->nullOnDelete();
        });

        // Speed up the dispatcher's cross-merchant queue view
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->index(['delivery_type', 'batch_id', 'status'], 'orders_kirim_batch_status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex('orders_kirim_batch_status');
            $table->dropForeign(['batch_id']);
            $table->dropForeign(['depot_id']);
            $table->dropColumn(['delivery_type', 'depot_id', 'batch_id']);
        });
    }
};
