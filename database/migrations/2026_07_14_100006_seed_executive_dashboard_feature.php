<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $merchantIds = DB::table('merchants')->whereNull('deleted_at')->pluck('id');

        foreach ($merchantIds as $id) {
            DB::table('merchant_features')->insertOrIgnore([
                'merchant_id' => $id,
                'feature'     => 'executive_dashboard',
                'is_enabled'  => true,
                'config'      => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('merchant_features')
            ->where('feature', 'executive_dashboard')
            ->delete();
    }
};
