<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            // The feature key, e.g. 'customer_intelligence', 'insights', 'growth', 'subscription'
            $table->string('feature', 100);
            $table->boolean('is_enabled')->default(false);
            // Optional JSON payload for feature-specific configuration
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'feature']);
            $table->index(['merchant_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_features');
    }
};
