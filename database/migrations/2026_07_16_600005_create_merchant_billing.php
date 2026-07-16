<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_billing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->unique();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->unsignedBigInteger('invoice_amount')->nullable();
            $table->date('due_date')->nullable();
            $table->string('payment_status', 20)->default('unpaid');
            $table->timestamp('last_payment_at')->nullable();
            $table->unsignedBigInteger('outstanding_balance')->default(0);
            $table->date('renewal_date')->nullable();
            $table->text('billing_notes')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('merchant_subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_billing');
    }
};
