<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: expand the ENUM to include both old and new values so the backfill UPDATEs are valid
        DB::statement("ALTER TABLE merchant_settings MODIFY routing_algorithm ENUM('nearest_neighbor','scored','hybrid','balanced','distance','vip') NOT NULL DEFAULT 'balanced'");

        // Step 2: backfill legacy values to new names
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'balanced' WHERE routing_algorithm IN ('scored', 'hybrid')");
        DB::statement("UPDATE merchant_settings SET routing_algorithm = 'distance' WHERE routing_algorithm = 'nearest_neighbor'");

        // Step 3: narrow the ENUM to only the values the application uses
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
