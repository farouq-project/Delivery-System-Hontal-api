<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_api_usage_logs', function (Blueprint $table) {
            $table->boolean('cache_hit')->default(false)->after('estimated_units');
            $table->string('cache_key', 100)->nullable()->after('cache_hit');
            $table->unsignedSmallInteger('response_time_ms')->nullable()->after('cache_key');
        });
    }

    public function down(): void
    {
        Schema::table('google_api_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['cache_hit', 'cache_key', 'response_time_ms']);
        });
    }
};
