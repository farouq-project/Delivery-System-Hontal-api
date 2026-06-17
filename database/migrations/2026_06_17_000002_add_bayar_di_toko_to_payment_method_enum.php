<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN payment_method ENUM('cash','transfer','qris','bayar_di_toko') NULL");
    }

    public function down(): void
    {
        // Remove any bayar_di_toko rows before reverting
        DB::statement("UPDATE delivery_orders SET payment_method = NULL WHERE payment_method = 'bayar_di_toko'");
        DB::statement("ALTER TABLE delivery_orders MODIFY COLUMN payment_method ENUM('cash','transfer','qris') NULL");
    }
};
