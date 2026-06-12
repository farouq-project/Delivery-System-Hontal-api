<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained('merchants')->cascadeOnDelete();
            $table->text('depot_address')->nullable();
            $table->decimal('depot_latitude', 10, 8)->nullable();
            $table->decimal('depot_longitude', 11, 8)->nullable();
            $table->enum('routing_algorithm', ['nearest_neighbor', 'scored', 'hybrid'])->default('scored');
            $table->boolean('auto_geocode_enabled')->default(true);
            $table->integer('max_stops_per_driver')->default(30);
            $table->time('working_hours_start')->default('07:00:00');
            $table->time('working_hours_end')->default('17:00:00');
            $table->integer('gps_ping_interval_sec')->default(30);
            $table->integer('gps_history_days')->default(30);
            $table->timestamps();
        });

        Schema::create('vip_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->enum('vip_level', ['standard', 'silver', 'gold', 'platinum']);
            $table->integer('score_value')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'vip_level']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_name');
            $table->string('phone', 20);
            $table->enum('vehicle_type', ['motorcycle', 'car', 'pickup_truck', 'van', 'truck'])->default('motorcycle');
            $table->string('vehicle_plate', 20);
            $table->decimal('vehicle_capacity_kg', 8, 2)->nullable();
            $table->enum('status', ['available', 'delivering', 'offline'])->default('offline');
            $table->decimal('current_lat', 10, 8)->nullable();
            $table->decimal('current_lng', 11, 8)->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'status']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('default_address');
            $table->decimal('default_latitude', 10, 8)->nullable();
            $table->decimal('default_longitude', 11, 8)->nullable();
            $table->enum('vip_level', ['standard', 'silver', 'gold', 'platinum'])->default('standard');
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id']);
            $table->index(['merchant_id', 'customer_name']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->json('error_log')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vip_configs');
        Schema::dropIfExists('merchant_settings');
    }
};
