<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('api_type', 50); // 'geocoding', 'distance_matrix', 'routes'
            $table->unsignedInteger('request_count')->default(1);
            $table->unsignedInteger('estimated_units')->default(1);
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->index(['merchant_id', 'api_type', 'created_at'], 'google_api_usage_merchant_type_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_api_usage_logs');
    }
};
