<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('routing_mode', 20)->nullable()->after('generation_method');
            $table->unsignedInteger('distance_before_optimization_m')->nullable()->after('total_distance_m');
            $table->unsignedInteger('optimization_saving_m')->nullable()->after('distance_before_optimization_m');
            $table->unsignedSmallInteger('google_calls')->default(0)->after('optimization_saving_m');
            $table->unsignedSmallInteger('cache_hits')->default(0)->after('google_calls');
            $table->decimal('quality_score', 5, 1)->nullable()->after('cache_hits');
            $table->unsignedTinyInteger('batch_count')->default(1)->after('quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn([
                'routing_mode',
                'distance_before_optimization_m',
                'optimization_saving_m',
                'google_calls',
                'cache_hits',
                'quality_score',
                'batch_count',
            ]);
        });
    }
};
