<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hontal_kirim_credits', function (Blueprint $table) {
            $table->id();
            // 1:1 with merchant — accessed by direct lookup, not MerchantScope
            $table->foreignId('merchant_id')->unique()->constrained('merchants')->cascadeOnDelete();
            // Integer IDR — Rp 10,000 per delivery; blocks order creation at 0
            $table->bigInteger('balance_idr')->default(0);
            // Low-balance alert fires when balance drops below this (default = 5 deliveries)
            $table->integer('low_balance_threshold_idr')->default(50000);
            // Deduplication: reset when topped up above threshold
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hontal_kirim_credits');
    }
};
