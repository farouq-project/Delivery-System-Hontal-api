<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── Platform settings (insertOrIgnore: safe to re-run if migration partially failed) ─
        DB::table('platform_settings')->insertOrIgnore([
            ['key' => 'kirim_service_radius_km',       'value' => '25',    'type' => 'integer', 'description' => 'Haversine radius from Bandung center beyond which Kirim orders are rejected.',  'created_at' => $now, 'updated_at' => $now],
            ['key' => 'kirim_delivery_fee_idr',         'value' => '10000', 'type' => 'integer', 'description' => 'Flat Kirim delivery fee per order in IDR.',                                     'created_at' => $now, 'updated_at' => $now],
            ['key' => 'kirim_low_balance_threshold_idr','value' => '50000', 'type' => 'integer', 'description' => 'Default low-balance alert threshold per merchant credit account (5 deliveries).','created_at' => $now, 'updated_at' => $now],
            ['key' => 'hontal_internal_merchant_id',    'value' => '0',     'type' => 'integer', 'description' => 'Merchant ID of the Hontal Internal mitra driver pool. Updated by this migration.','created_at' => $now, 'updated_at' => $now],
        ]);

        // ── Hontal Internal merchant (mitra driver pool) ───────────────────────
        $existingSlug = DB::table('merchants')->where('slug', 'hontal-internal')->first();

        if ($existingSlug) {
            $merchantId = $existingSlug->id;
        } else {
            $merchantId = DB::table('merchants')->insertGetId([
                'ulid'         => (string) Str::ulid(),
                'company_name' => 'Hontal Internal',
                'slug'         => 'hontal-internal',
                'email'        => 'internal@hontal.id',
                'timezone'     => 'Asia/Jakarta',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            // Insert settings only if not already present (guards against partial-run retry)
            $hasSettings = DB::table('merchant_settings')->where('merchant_id', $merchantId)->exists();
            if (! $hasSettings) {
                DB::table('merchant_settings')->insert([
                    'merchant_id'            => $merchantId,
                    'routing_algorithm'      => 'balanced', // 'scored' removed from ENUM in 2026_07_14_200001
                    'auto_geocode_enabled'   => true,
                    'max_stops_per_driver'   => 30,
                    'working_hours_start'    => '07:00:00',
                    'working_hours_end'      => '20:00:00',
                    'gps_ping_interval_sec'  => 30,
                    'gps_history_days'       => 30,
                    'public_tracking_enabled'=> true,
                    'driver_location_visible'=> true,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);
            }
        }

        DB::table('platform_settings')
            ->where('key', 'hontal_internal_merchant_id')
            ->update(['value' => (string) $merchantId, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'kirim_service_radius_km',
            'kirim_delivery_fee_idr',
            'kirim_low_balance_threshold_idr',
            'hontal_internal_merchant_id',
        ])->delete();
        // Intentionally NOT deleting the Hontal Internal merchant to avoid cascading data loss
    }
};
