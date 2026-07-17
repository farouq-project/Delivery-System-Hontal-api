<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('vehicle_nickname', 80)->nullable()->after('vehicle_plate');
            $table->string('vehicle_image_path')->nullable()->after('vehicle_nickname');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['vehicle_nickname', 'vehicle_image_path']);
        });
    }
};
