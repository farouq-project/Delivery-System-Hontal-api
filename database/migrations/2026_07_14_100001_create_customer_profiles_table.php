<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();

            // Auto-maintained order stats
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('total_deliveries')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->decimal('total_spending', 15, 2)->default(0);
            $table->decimal('avg_order_value', 15, 2)->default(0);
            $table->decimal('avg_delivery_time_hours', 8, 2)->nullable();

            // Preference detection (most frequent from delivered orders)
            $table->string('preferred_payment', 50)->nullable();
            $table->string('preferred_delivery_time', 50)->nullable();

            // Health and segmentation (auto-classified)
            $table->string('health_status', 30)->default('healthy');
            $table->string('segment', 30)->default('new');
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('last_segment_check_at')->nullable();

            $table->timestamps();

            $table->index('merchant_id');
            $table->index('health_status');
            $table->index('segment');
        });

        // Enable customer_domain feature for all existing merchants
        DB::table('merchants')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->each(function (int $merchantId) {
                DB::table('merchant_features')->insertOrIgnore([
                    'merchant_id' => $merchantId,
                    'feature'     => 'customer_domain',
                    'is_enabled'  => true,
                    'config'      => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
        DB::table('merchant_features')->where('feature', 'customer_domain')->delete();
    }
};
