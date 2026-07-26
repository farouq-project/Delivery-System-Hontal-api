<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-defined credit pack options (managed by platform admin)
        Schema::create('credit_pack_options', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);          // e.g. "Small Pack"
            $table->string('slug', 30)->unique(); // e.g. "small"
            $table->unsignedInteger('credits');   // credits granted
            $table->unsignedInteger('price_idr'); // price in IDR
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->timestamps();
        });

        // Record of credits granted to a specific merchant
        Schema::create('merchant_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('merchant_subscriptions')->cascadeOnDelete();
            $table->foreignId('credit_pack_option_id')->nullable()->constrained('credit_pack_options')->nullOnDelete();
            $table->unsignedInteger('credits_granted');
            $table->unsignedInteger('price_paid_idr')->default(0);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('merchant_id');
            $table->index('subscription_id');
        });

        // Seed standard credit pack options
        DB::table('credit_pack_options')->insert([
            ['name' => 'Small Pack',    'slug' => 'small',    'credits' => 100,  'price_idr' => 200000, 'is_active' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Medium Pack',   'slug' => 'medium',   'credits' => 250,  'price_idr' => 450000, 'is_active' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Large Pack',    'slug' => 'large',    'credits' => 500,  'price_idr' => 800000, 'is_active' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_credit_purchases');
        Schema::dropIfExists('credit_pack_options');
    }
};
