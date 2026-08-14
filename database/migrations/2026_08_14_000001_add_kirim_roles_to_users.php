<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adds: kasir (fixes pre-existing gap), merchant_ops, hontal_dispatcher
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM(
                'super_admin','developer','merchant_owner','dispatcher','driver',
                'kasir','merchant_ops','hontal_dispatcher'
            ) DEFAULT 'dispatcher'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY role ENUM(
                'super_admin','developer','merchant_owner','dispatcher','driver'
            ) DEFAULT 'dispatcher'");
        }
    }
};
