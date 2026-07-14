<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend merchants with branding and invoice fields
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('tax_number', 50)->nullable()->after('logo_path');
            $table->text('invoice_footer')->nullable()->after('tax_number');
            $table->string('brand_color', 7)->nullable()->after('invoice_footer');
        });

        // 2. Extend merchant_settings with Phase 3 operational, tracking, notification, invoice fields
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->json('working_days')->nullable()->after('working_hours_end');
            $table->boolean('holiday_mode_enabled')->default(false)->after('working_days');
            $table->unsignedSmallInteger('max_delivery_radius_km')->nullable()->after('holiday_mode_enabled');
            $table->boolean('auto_dispatch')->default(false)->after('max_delivery_radius_km');
            $table->unsignedSmallInteger('tracking_expiry_hours')->default(48)->after('auto_dispatch');
            $table->boolean('public_tracking_enabled')->default(true)->after('tracking_expiry_hours');
            $table->boolean('show_estimated_arrival')->default(true)->after('public_tracking_enabled');
            $table->boolean('driver_location_visible')->default(true)->after('show_estimated_arrival');
            $table->boolean('whatsapp_notifications_enabled')->default(false)->after('driver_location_visible');
            $table->boolean('email_notifications_enabled')->default(false)->after('whatsapp_notifications_enabled');
            $table->boolean('push_notifications_enabled')->default(false)->after('email_notifications_enabled');
            $table->string('invoice_prefix', 20)->nullable()->after('push_notifications_enabled');
            $table->string('invoice_date_format', 20)->nullable()->after('invoice_prefix');
        });

        // 3. Per-merchant payment method catalogue
        Schema::create('merchant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('method_key', 30);
            $table->string('label', 100);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'method_key']);
        });

        // 4. Branch / depot registry
        Schema::create('merchant_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('address', 500)->nullable();
            $table->decimal('depot_latitude', 10, 7)->nullable();
            $table->decimal('depot_longitude', 10, 7)->nullable();
            $table->string('working_hours_start', 5)->nullable();
            $table->string('working_hours_end', 5)->nullable();
            $table->json('working_days')->nullable();
            $table->unsignedSmallInteger('max_stops_per_driver')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 5. Seed default payment methods for all existing merchants
        $defaultMethods = [
            ['method_key' => 'cash',         'label' => 'Tunai',         'is_enabled' => true,  'is_default' => true,  'sort_order' => 1],
            ['method_key' => 'transfer',      'label' => 'Transfer Bank', 'is_enabled' => true,  'is_default' => false, 'sort_order' => 2],
            ['method_key' => 'qris',          'label' => 'QRIS',          'is_enabled' => true,  'is_default' => false, 'sort_order' => 3],
            ['method_key' => 'bayar_di_toko', 'label' => 'Bayar di Toko', 'is_enabled' => true,  'is_default' => false, 'sort_order' => 4],
            ['method_key' => 'edc',           'label' => 'EDC / Kartu',   'is_enabled' => false, 'is_default' => false, 'sort_order' => 5],
        ];

        $merchantIds = DB::table('merchants')->whereNull('deleted_at')->pluck('id');

        foreach ($merchantIds as $merchantId) {
            foreach ($defaultMethods as $method) {
                DB::table('merchant_payment_methods')->insertOrIgnore([
                    'merchant_id' => $merchantId,
                    ...$method,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // 6. Seed merchant_platform feature flag
            DB::table('merchant_features')->insertOrIgnore([
                'merchant_id' => $merchantId,
                'feature'     => 'merchant_platform',
                'is_enabled'  => true,
                'config'      => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_branches');
        Schema::dropIfExists('merchant_payment_methods');

        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn([
                'working_days', 'holiday_mode_enabled', 'max_delivery_radius_km',
                'auto_dispatch', 'tracking_expiry_hours', 'public_tracking_enabled',
                'show_estimated_arrival', 'driver_location_visible',
                'whatsapp_notifications_enabled', 'email_notifications_enabled',
                'push_notifications_enabled', 'invoice_prefix', 'invoice_date_format',
            ]);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['tax_number', 'invoice_footer', 'brand_color']);
        });

        DB::table('merchant_features')->where('feature', 'merchant_platform')->delete();
    }
};
