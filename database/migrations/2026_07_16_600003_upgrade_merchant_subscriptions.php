<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('trial_ends_at');
            $table->timestamp('resumed_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'resumed_at']);
        });
    }
};
