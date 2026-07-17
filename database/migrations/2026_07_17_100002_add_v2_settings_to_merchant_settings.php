<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            // Routing mode: economy (Haversine only) | balanced (Haversine+2-opt) | optimized (+Google)
            $table->string('routing_mode', 20)->default('balanced')->after('routing_algorithm');
            // Configurable Distance Matrix cache TTL (seconds); null = use system default (600s)
            $table->unsignedSmallInteger('distance_matrix_cache_ttl')->nullable()->after('routing_mode');
            // Batch enforcement: when true, batches are separated by time-of-day before sequencing
            $table->boolean('batch_enforcement')->default(true)->after('distance_matrix_cache_ttl');
            // Two-opt: can be disabled per merchant if N is large and speed matters more than quality
            $table->boolean('two_opt_enabled')->default(true)->after('batch_enforcement');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn(['routing_mode', 'distance_matrix_cache_ttl', 'batch_enforcement', 'two_opt_enabled']);
        });
    }
};
