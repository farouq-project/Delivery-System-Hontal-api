<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('order_number', 50)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // Customer snapshot
            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();

            // Product info
            $table->string('product_name');
            $table->text('product_notes')->nullable();
            $table->decimal('order_value', 15, 2)->default(0);

            // Delivery address snapshot
            $table->text('delivery_address');
            $table->decimal('delivery_latitude', 10, 8)->nullable();
            $table->decimal('delivery_longitude', 11, 8)->nullable();
            $table->text('delivery_notes')->nullable();

            // Delivery window
            $table->date('requested_delivery_date')->nullable();
            $table->time('requested_delivery_start')->nullable();
            $table->time('requested_delivery_end')->nullable();

            // Status
            $table->enum('status', ['pending', 'assigned', 'in_progress', 'delivered', 'failed', 'cancelled'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Timestamps
            $table->timestamp('order_created_at')->useCurrent();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Routing
            $table->integer('route_sequence')->nullable();
            $table->integer('estimated_distance_m')->nullable();
            $table->integer('estimated_duration_min')->nullable();
            $table->integer('actual_distance_m')->nullable();

            // External
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->string('external_order_id', 100)->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'requested_delivery_date']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 50)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });

        Schema::create('proof_of_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('photo_path', 500)->nullable();
            $table->string('photo_thumbnail', 500)->nullable();
            $table->decimal('captured_latitude', 10, 8)->nullable();
            $table->decimal('captured_longitude', 11, 8)->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('delivery_orders');
    }
};
