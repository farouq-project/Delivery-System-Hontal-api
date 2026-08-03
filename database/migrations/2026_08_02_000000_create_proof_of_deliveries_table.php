<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proof_of_deliveries')) {
            return;
        }

        Schema::create('proof_of_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('photo_thumbnail')->nullable();
            $table->decimal('captured_latitude', 10, 8)->nullable();
            $table->decimal('captured_longitude', 11, 8)->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('notes')->nullable();
            $table->datetime('captured_at')->nullable();
            $table->datetime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
    }
};
