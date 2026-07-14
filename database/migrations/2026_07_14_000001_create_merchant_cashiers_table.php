<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_cashiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
            $table->index('merchant_id');
        });

        // Seed the hardcoded cashier names for every existing merchant so
        // validation continues to work immediately after this migration runs.
        $defaultNames = ['Mian', 'Sela', 'Epa', 'Tira'];

        $merchantIds = DB::table('merchants')->whereNull('deleted_at')->pluck('id');

        foreach ($merchantIds as $merchantId) {
            foreach ($defaultNames as $name) {
                DB::table('merchant_cashiers')->insertOrIgnore([
                    'merchant_id' => $merchantId,
                    'name'        => $name,
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_cashiers');
    }
};
