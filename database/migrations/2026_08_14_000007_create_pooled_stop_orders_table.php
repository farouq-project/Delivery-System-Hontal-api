<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot: a pickup stop may collect orders from multiple merchants at the same depot
        Schema::create('pooled_stop_orders', function (Blueprint $table) {
            $table->foreignId('pooled_stop_id')->constrained('pooled_stops')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();

            $table->primary(['pooled_stop_id', 'order_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pooled_stop_orders');
    }
};
