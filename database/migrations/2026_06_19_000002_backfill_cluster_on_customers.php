<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLUSTERS = [
        'Banyak','Candra','Guru','Jingga','Kama','Kidang','Kumala','Larang',
        'Loka','Mayang','Naga','Naya','Pita','Purba','Rambut','Ratna',
        'Sima','Subang','Taru','Teja','Titis','Wangsa',
    ];

    public function up(): void
    {
        // Set each named cluster first (case-insensitive name match)
        foreach (self::CLUSTERS as $cluster) {
            DB::statement(
                "UPDATE customers SET cluster = ? WHERE cluster IS NULL AND LOWER(customer_name) LIKE ?",
                [$cluster, '%' . strtolower($cluster) . '%']
            );
        }

        // Any remaining nulls get 'no cluster'
        DB::statement("UPDATE customers SET cluster = 'no cluster' WHERE cluster IS NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE customers SET cluster = NULL WHERE cluster = 'no cluster'");
    }
};
