<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test env) stores ENUM as VARCHAR — MODIFY ENUM is MySQL-only
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','developer','merchant_owner','dispatcher','driver') DEFAULT 'dispatcher'");
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->json('items')->nullable()->after('product_notes');
        });

        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->unsignedInteger('klotter_size')->default(7)->after('max_stops_per_driver');
        });

        Schema::create('product_catalog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE customers MODIFY default_address TEXT NULL");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE customers MODIFY default_address TEXT NOT NULL");
        }

        Schema::dropIfExists('product_catalog');

        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('klotter_size');
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('items');
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin','merchant_owner','dispatcher','driver') DEFAULT 'dispatcher'");
        }
    }
};
