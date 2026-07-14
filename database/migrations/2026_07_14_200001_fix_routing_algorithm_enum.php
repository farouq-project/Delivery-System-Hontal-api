<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill legacy enum values to new names before altering the column
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'balanced'  WHERE routing_algorithm IN ('scored', 'hybrid')");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'distance'  WHERE routing_algorithm = 'nearest_neighbor'");

        // Replace the enum constraint with the values the application actually uses
        DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('balanced','distance','vip') NOT NULL DEFAULT 'balanced'");
    }

    public function down(): void
    {
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'scored'            WHERE routing_algorithm = 'balanced'");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'nearest_neighbor'  WHERE routing_algorithm = 'distance'");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'scored'            WHERE routing_algorithm = 'vip'");

        DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('nearest_neighbor','scored','hybrid') NOT NULL DEFAULT 'scored'");
    }
};
