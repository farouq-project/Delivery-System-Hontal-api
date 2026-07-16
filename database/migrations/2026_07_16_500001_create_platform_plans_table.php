<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('monthly_price')->default(0); // IDR, no decimals
            $table->unsignedInteger('delivery_limit')->nullable();   // null = unlimited
            $table->unsignedTinyInteger('branch_limit')->nullable();
            $table->unsignedSmallInteger('driver_limit')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_plans');
    }
};
