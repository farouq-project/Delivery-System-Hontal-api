<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in tests) stores ENUM as VARCHAR — the ALTER TABLE MODIFY
        // syntax is MySQL-only, but the backfill UPDATEs are safe on both drivers.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('nearest_neighbor','scored','hybrid','balanced','distance','vip') NOT NULL DEFAULT 'balanced'");
        }

        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'balanced' WHERE routing_algorithm IN ('scored', 'hybrid')");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'distance' WHERE routing_algorithm = 'nearest_neighbor'");

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('balanced','distance','vip') NOT NULL DEFAULT 'balanced'");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'scored'           WHERE routing_algorithm = 'balanced'");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'nearest_neighbor' WHERE routing_algorithm = 'distance'");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'scored'           WHERE routing_algorithm = 'vip'");

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('nearest_neighbor','scored','hybrid') NOT NULL DEFAULT 'scored'");
        }
    }
};
