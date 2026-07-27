<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->enum('platform', ['whatsapp', 'instagram', 'facebook', 'tiktok', 'google', 'offline', 'other'])->default('other');
            $table->enum('lead_source', ['google', 'meta', 'instagram', 'whatsapp', 'tiktok', 'referral', 'marketplace', 'offline', 'walk_in', 'other'])->default('other');
            $table->enum('campaign_type', ['broadcast', 'promo', 'referral', 'seasonal', 'retention', 'acquisition'])->default('broadcast');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('budget', 15, 2)->default(0);
            $table->decimal('spend', 15, 2)->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('wa_conversations')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('customers_acquired')->default(0);
            $table->decimal('attributed_revenue', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'start_date']);
            $table->foreign('merchant_id')->references('id')->on('merchants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
