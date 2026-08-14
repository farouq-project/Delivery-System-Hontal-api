<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable ledger — no updated_at column
        Schema::create('hontal_kirim_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->enum('type', ['topup', 'debit']);
            // Positive integer. Debits decrement balance by this amount.
            $table->bigInteger('amount_idr');
            // Stored per-transaction so future pricing changes don't need a migration
            $table->bigInteger('fee_amount_idr')->default(10000);
            // Set for debits on completed delivery; null for top-ups
            $table->foreignId('order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->text('note')->nullable();
            // Operator for top-ups; system user id for auto-debits
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_id', 'created_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hontal_kirim_credit_transactions');
    }
};
