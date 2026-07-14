<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN payment_method ENUM('cash','transfer','qris','bayar_di_toko') NULL");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE delivery_orders SET payment_method = NULL WHERE payment_method = 'bayar_di_toko'");

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN payment_method ENUM('cash','transfer','qris') NULL");
        }
    }
};
