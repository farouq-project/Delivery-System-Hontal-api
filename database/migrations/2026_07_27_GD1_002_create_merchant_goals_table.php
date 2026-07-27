<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->enum('metric', ['revenue', 'orders', 'customers', 'success_rate', 'new_customers']);
            $table->decimal('target_value', 15, 2);
            $table->enum('period_type', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'period_start', 'period_end']);
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_goals');
    }
};
