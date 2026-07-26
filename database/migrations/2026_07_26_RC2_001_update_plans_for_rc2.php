<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add routing tier column to plans
        Schema::table('platform_plans', function (Blueprint $table) {
            $table->string('included_routing_mode', 30)->default('balanced')->after('delivery_limit');
        });

        // Update plan data: new delivery credit limits, 7-day trial, routing tiers
        DB::table('platform_plans')->where('slug', 'starter')->update([
            'delivery_limit'         => 150,
            'trial_days'             => 7,
            'included_routing_mode'  => 'economy',
        ]);
        DB::table('platform_plans')->where('slug', 'growth')->update([
            'delivery_limit'         => 600,
            'trial_days'             => 7,
            'included_routing_mode'  => 'balanced',
        ]);
        DB::table('platform_plans')->where('slug', 'professional')->update([
            'delivery_limit'         => 1500,
            'trial_days'             => 7,
            'included_routing_mode'  => 'optimized',
        ]);
        DB::table('platform_plans')->where('slug', 'enterprise')->update([
            'delivery_limit'         => null,
            'trial_days'             => 7,
            'included_routing_mode'  => 'optimized',
        ]);

        // Update platform_settings default_trial_days to 7
        DB::table('platform_settings')->where('key', 'default_trial_days')->update(['value' => '7']);
    }

    public function down(): void
    {
        DB::table('platform_plans')->where('slug', 'starter')->update([
            'delivery_limit' => 500, 'trial_days' => 14, 'included_routing_mode' => 'balanced',
        ]);
        DB::table('platform_plans')->where('slug', 'growth')->update([
            'delivery_limit' => 2000, 'trial_days' => 14, 'included_routing_mode' => 'balanced',
        ]);
        DB::table('platform_plans')->where('slug', 'professional')->update([
            'delivery_limit' => 10000, 'trial_days' => 14, 'included_routing_mode' => 'balanced',
        ]);
        DB::table('platform_plans')->where('slug', 'enterprise')->update([
            'delivery_limit' => null, 'trial_days' => 14, 'included_routing_mode' => 'balanced',
        ]);
        DB::table('platform_settings')->where('key', 'default_trial_days')->update(['value' => '14']);

        Schema::table('platform_plans', function (Blueprint $table) {
            $table->dropColumn('included_routing_mode');
        });
    }
};
