<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pooled_routes', function (Blueprint $table) {
            $table->id();
            // Nullable: a route may serve overflow from a later batch
            $table->foreignId('batch_id')->nullable()->constrained('hontal_kirim_batches')->nullOnDelete();
            // Must be a mitra driver in the Hontal Internal merchant — application-enforced
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->enum('status', ['queued', 'active', 'completed', 'cancelled'])->default('queued');
            $table->integer('total_stops')->default(0);
            $table->integer('completed_stops')->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_routes');
    }
};
