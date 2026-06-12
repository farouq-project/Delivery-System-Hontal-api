<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->date('route_date');
            $table->string('label', 100)->nullable();
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            $table->integer('total_stops')->default(0);
            $table->integer('total_drivers')->default(0);
            $table->integer('total_distance_m')->default(0);
            $table->integer('estimated_duration_min')->default(0);
            $table->enum('generation_method', ['auto', 'manual', 'reoptimized'])->default('auto');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'route_date']);
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('route_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->integer('sequence_number')->default(1);
            $table->timestamp('estimated_start_at')->nullable();
            $table->timestamp('estimated_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->integer('total_stops')->default(0);
            $table->integer('completed_stops')->default(0);
            $table->integer('failed_stops')->default(0);
            $table->integer('total_distance_m')->default(0);
            $table->enum('status', ['pending', 'active', 'completed', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->unique(['route_id', 'driver_id']);
            $table->index('driver_id');
            $table->index('status');
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('route_assignment_id')->constrained('route_assignments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->integer('stop_sequence');
            $table->decimal('distance_score', 8, 2)->default(0);
            $table->decimal('waiting_score', 8, 2)->default(0);
            $table->decimal('window_score', 8, 2)->default(0);
            $table->decimal('vip_score', 8, 2)->default(0);
            $table->decimal('total_score', 8, 2)->default(0);
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('actual_arrival')->nullable();
            $table->integer('distance_from_prev_m')->nullable();
            $table->integer('duration_from_prev_min')->nullable();
            $table->boolean('is_manually_placed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->index('route_id');
            $table->index('route_assignment_id');
            $table->index('order_id');
            $table->unique(['route_assignment_id', 'stop_sequence']);
        });

        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('route_assignment_id')->nullable()->constrained('route_assignments')->nullOnDelete();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->float('accuracy_m')->nullable();
            $table->float('speed_kmh')->nullable();
            $table->float('bearing_deg')->nullable();
            $table->tinyInteger('battery_pct')->unsigned()->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_id', 'recorded_at']);
            $table->index(['merchant_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('route_assignments');
        Schema::dropIfExists('routes');
    }
};
