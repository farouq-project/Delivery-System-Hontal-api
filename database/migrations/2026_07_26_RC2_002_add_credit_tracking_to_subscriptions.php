<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            // Credits consumed this billing cycle
            $table->unsignedInteger('credits_used')->default(0)->after('trial_ends_at');
            // Extra credits from purchased packs (accumulated, not cycle-scoped)
            $table->unsignedInteger('extra_credits')->default(0)->after('credits_used');
            // When credits_used was last reset (start of billing cycle)
            $table->timestamp('credits_reset_at')->nullable()->after('extra_credits');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['credits_used', 'extra_credits', 'credits_reset_at']);
        });
    }
};
