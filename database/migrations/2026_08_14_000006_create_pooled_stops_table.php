<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pooled_route_id')->constrained('pooled_routes')->cascadeOnDelete();
            $table->smallInteger('stop_sequence');
            $table->enum('stop_type', ['pickup', 'dropoff']);

            // pickup stop: depot_id set; dropoff stop: order_id set
            $table->foreignId('depot_id')->nullable()->constrained('depots')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();

            // Denormalized at stop creation so driver PWA never needs a join
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();

            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('actual_arrival')->nullable();

            // Haversine from previous stop's coordinates, computed at completion
            $table->integer('distance_from_prev_m')->nullable();

            // True if added after driver started this route
            $table->boolean('is_mid_route_addition')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['pooled_route_id', 'stop_sequence']);
            $table->index('order_id');
            $table->index('depot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_stops');
    }
};
