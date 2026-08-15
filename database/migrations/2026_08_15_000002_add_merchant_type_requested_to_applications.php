<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_applications', function (Blueprint $table) {
            $table->string('merchant_type_requested', 20)->nullable()->after('selected_plan');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_applications', function (Blueprint $table) {
            $table->dropColumn('merchant_type_requested');
        });
    }
};
