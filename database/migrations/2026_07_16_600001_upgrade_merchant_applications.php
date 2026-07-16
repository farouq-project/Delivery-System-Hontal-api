<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_applications', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('notes');
            $table->text('internal_notes')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_applications', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'internal_notes']);
        });
    }
};
