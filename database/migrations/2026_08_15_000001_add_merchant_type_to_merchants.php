<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // 'sistem'   = merchant owns drivers, uses Hontal routing software only
            // 'kirim'    = merchant submits orders to Hontal's pooled delivery network
            // 'internal' = Hontal's own internal entity (mitra drivers, dispatchers)
            $table->string('merchant_type', 20)->nullable()->after('email');
        });

        // Hontal Internal gets marked immediately
        DB::table('merchants')
            ->where('slug', 'hontal-internal')
            ->update(['merchant_type' => 'internal']);

        // All other existing merchants default to 'sistem'
        DB::table('merchants')
            ->where('slug', '!=', 'hontal-internal')
            ->whereNull('merchant_type')
            ->update(['merchant_type' => 'sistem']);
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('merchant_type');
        });
    }
};
