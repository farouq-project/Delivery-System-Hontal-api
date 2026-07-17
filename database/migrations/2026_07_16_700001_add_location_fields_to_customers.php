<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('location_source', 30)->default('unknown')->after('default_longitude');
            $table->timestamp('location_last_verified_at')->nullable()->after('location_source');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['location_source', 'location_last_verified_at']);
        });
    }
};
