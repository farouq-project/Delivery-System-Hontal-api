<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['merchant_id', 'name']);
            $table->index('merchant_id');
        });

        // Seed the 22 hardcoded cluster names for every existing merchant so
        // cluster detection continues to work immediately after migration runs.
        $defaultClusters = [
            'Banyak', 'Candra', 'Guru',   'Jingga', 'Kama',   'Kidang',
            'Kumala', 'Larang', 'Loka',   'Mayang', 'Naga',   'Naya',
            'Pita',   'Purba',  'Rambut', 'Ratna',  'Sima',   'Subang',
            'Taru',   'Teja',   'Titis',  'Wangsa',
        ];

        $merchantIds = DB::table('merchants')->whereNull('deleted_at')->pluck('id');

        foreach ($merchantIds as $merchantId) {
            foreach ($defaultClusters as $cluster) {
                DB::table('merchant_clusters')->insertOrIgnore([
                    'merchant_id' => $merchantId,
                    'name'        => $cluster,
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_clusters');
    }
};
