<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, integer, boolean, json
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed defaults
        $now = now();
        DB::table('platform_settings')->insert([
            ['key' => 'default_trial_days',           'value' => '14',    'type' => 'integer', 'description' => 'Default trial period in days for new merchants.',            'created_at' => $now, 'updated_at' => $now],
            ['key' => 'default_tracking_expiry_hours', 'value' => '48',    'type' => 'integer', 'description' => 'How long tracking links remain active after delivery.',       'created_at' => $now, 'updated_at' => $now],
            ['key' => 'google_api_warning_threshold',  'value' => '1000',  'type' => 'integer', 'description' => 'Monthly Google API unit threshold that triggers a warning.',   'created_at' => $now, 'updated_at' => $now],
            ['key' => 'maintenance_mode',              'value' => 'false', 'type' => 'boolean', 'description' => 'When true, the platform shows a maintenance message.',        'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
