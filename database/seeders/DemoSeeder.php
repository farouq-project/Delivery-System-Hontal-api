<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\User;
use App\Models\VipConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo seeder for screen recording — "Kencana Lima" distributor.
 * Creates 6 months of historical delivery data for full BI analytics.
 *
 * Run: php8.3 artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private array $areas = [
        ['name' => 'Dago',         'lat' => -6.8901, 'lng' => 107.6090],
        ['name' => 'Buah Batu',    'lat' => -6.9501, 'lng' => 107.6426],
        ['name' => 'Antapani',     'lat' => -6.9141, 'lng' => 107.6638],
        ['name' => 'Cicendo',      'lat' => -6.9013, 'lng' => 107.5899],
        ['name' => 'Regol',        'lat' => -6.9268, 'lng' => 107.6106],
        ['name' => 'Kiaracondong', 'lat' => -6.9276, 'lng' => 107.6478],
        ['name' => 'Coblong',      'lat' => -6.8911, 'lng' => 107.6139],
        ['name' => 'Sukasari',     'lat' => -6.8812, 'lng' => 107.5985],
        ['name' => 'Cibeunying',   'lat' => -6.9001, 'lng' => 107.6389],
        ['name' => 'Bandung Wetan','lat' => -6.9007, 'lng' => 107.6234],
    ];

    private array $streets = [
        'Jl. Dago', 'Jl. Cihampelas', 'Jl. Setiabudhi', 'Jl. Pasteur',
        'Jl. Soekarno Hatta', 'Jl. Kiaracondong', 'Jl. Buah Batu',
        'Jl. Riau', 'Jl. Diponegoro', 'Jl. Laswi', 'Jl. Merdeka',
        'Jl. Braga', 'Jl. Asia Afrika', 'Jl. Lengkong', 'Jl. Gatot Subroto',
    ];

    private array $bizTypes = [
        'Warung', 'Toko', 'Depot', 'Minimart', 'Kedai',
        'Warung Makan', 'Toko Kelontong', 'Warung Sembako', 'Agen', 'Kios',
    ];

    private array $names = [
        'Budi', 'Sari', 'Ahmad', 'Dewi', 'Andi', 'Nina', 'Reza', 'Fitri',
        'Hendra', 'Yuli', 'Doni', 'Ratna', 'Rizki', 'Siti', 'Agus', 'Rina',
        'Farhan', 'Mega', 'Irwan', 'Tina', 'Wahyu', 'Lina', 'Bayu', 'Desi',
        'Candra', 'Putri', 'Lotek', 'Naga', 'Amertha', 'Jingga', 'Sima',
        'Kidang', 'Purba', 'Banyak', 'Pempek', 'Ratna', 'Loka',
    ];

    private array $products = [
        'Susu Segar 1L'        => 18000,
        'Susu Segar 5L'        => 75000,
        'Susu UHT 250ml x12'   => 52000,
        'Yogurt 500ml'         => 28000,
        'Keju 200g'            => 35000,
        'Mentega 250g'         => 22000,
        'Es Krim 1L'           => 45000,
        'Frozen Beef 1kg'      => 120000,
        'Frozen Chicken 2kg'   => 95000,
        'Sosis Ayam 500g'      => 38000,
        'Nugget 500g'          => 42000,
        'Bakso 1kg'            => 55000,
        'Telur Ayam 30pcs'     => 65000,
        'Telur Bebek 20pcs'    => 48000,
        'Susu Kambing 1L'      => 32000,
    ];

    private array $paymentMethods = ['transfer', 'cash', 'transfer', 'transfer', 'cod'];

    public function run(): void
    {
        // ── Wipe any previous demo run (makes seeder re-runnable) ─────────────
        $this->command->info('Cleaning up any previous demo data...');
        $prevMerchants = Merchant::withoutGlobalScopes()
            ->withTrashed()
            ->whereIn('slug', ['ombar-distribusi', 'kencana-lima'])
            ->orWhereIn('email', ['info@ombar.id', 'owner@ombar.id'])
            ->get();
        foreach ($prevMerchants as $prev) {
            DeliveryOrder::withoutGlobalScopes()->where('merchant_id', $prev->id)->forceDelete();
            Customer::withoutGlobalScopes()->where('merchant_id', $prev->id)->forceDelete();
            Driver::withoutGlobalScopes()->where('merchant_id', $prev->id)->forceDelete();
            User::where('merchant_id', $prev->id)->forceDelete();
            MerchantSetting::where('merchant_id', $prev->id)->delete();
            VipConfig::where('merchant_id', $prev->id)->delete();
            MerchantFeature::where('merchant_id', $prev->id)->delete();
            $prev->forceDelete();
            $this->command->info("Removed previous demo merchant: {$prev->slug}.");
        }

        $this->command->info('Creating Ombar Distribusi demo merchant...');

        // ── Merchant ──────────────────────────────────────────────────────────
        $merchant = Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'Ombar Distribusi',
            'slug'         => 'ombar-distribusi',
            'address'      => 'Jl. Soekarno Hatta No. 88, Bandung',
            'phone'        => '022-7654321',
            'email'        => 'info@ombar.id',
            'timezone'     => 'Asia/Jakarta',
        ]);

        MerchantSetting::create([
            'merchant_id'          => $merchant->id,
            'depot_address'        => 'Jl. Soekarno Hatta No. 88, Bandung',
            'depot_latitude'       => -6.9344,
            'depot_longitude'      => 107.6278,
            'max_stops_per_driver' => 35,
            'working_hours_start'  => '07:00:00',
            'working_hours_end'    => '17:00:00',
            'routing_algorithm'    => 'balanced',
            'routing_mode'         => 'balanced',
            'batch_enforcement'    => true,
            'two_opt_enabled'      => true,
        ]);

        foreach (['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200] as $level => $score) {
            VipConfig::create(['merchant_id' => $merchant->id, 'vip_level' => $level, 'score_value' => $score]);
        }

        // Enable BI features
        foreach (['executive_dashboard', 'merchant_platform', 'bi_module'] as $feat) {
            MerchantFeature::firstOrCreate(
                ['merchant_id' => $merchant->id, 'feature' => $feat],
                ['is_enabled' => true]
            );
        }

        // ── Users ─────────────────────────────────────────────────────────────
        $owner = User::create([
            'ulid' => Str::ulid(), 'merchant_id' => $merchant->id,
            'name' => 'Pak Deni', 'email' => 'owner@ombar.id',
            'password' => Hash::make('password'), 'role' => 'merchant_owner', 'is_active' => true,
        ]);
        User::create([
            'ulid' => Str::ulid(), 'merchant_id' => $merchant->id,
            'name' => 'Sari Admin', 'email' => 'dispatcher@ombar.id',
            'password' => Hash::make('password'), 'role' => 'dispatcher', 'is_active' => true,
        ]);

        // ── Drivers ───────────────────────────────────────────────────────────
        $driverData = [
            ['Andri Kurniawan',  'driver1@ombar.id', 'D 1234 KL', -6.9050, 107.6100],
            ['Bowo Susanto',     'driver2@ombar.id', 'D 5678 KL', -6.9200, 107.6350],
            ['Catur Wicaksono',  'driver3@ombar.id', 'D 9012 KL', -6.9400, 107.6500],
            ['Dedi Prasetyo',    'driver4@ombar.id', 'D 3456 KL', -6.8950, 107.6200],
            ['Eka Saputra',      'driver5@ombar.id', 'D 7890 KL', -6.9100, 107.6550],
        ];

        $drivers = [];
        foreach ($driverData as $i => [$name, $email, $plate, $lat, $lng]) {
            $du = User::create([
                'ulid' => Str::ulid(), 'merchant_id' => $merchant->id,
                'name' => $name, 'email' => $email,
                'password' => Hash::make('password'), 'role' => 'driver', 'is_active' => true,
            ]);
            $drivers[] = Driver::create([
                'ulid' => Str::ulid(), 'merchant_id' => $merchant->id, 'user_id' => $du->id,
                'driver_name' => $name, 'phone' => '0812-200' . ($i + 1) . '-000' . ($i + 1),
                'vehicle_type' => 'motorcycle', 'vehicle_plate' => $plate,
                'status' => 'available', 'current_lat' => $lat, 'current_lng' => $lng,
                'last_seen' => now()->subMinutes(rand(1, 10)),
            ]);
        }

        $this->command->info('5 drivers created.');

        // ── 250 Customers ─────────────────────────────────────────────────────
        $customers = [];
        for ($i = 0; $i < 250; $i++) {
            $area = $this->areas[$i % count($this->areas)];
            $name = $this->names[$i % count($this->names)];
            $biz  = $this->bizTypes[$i % count($this->bizTypes)];
            $st   = $this->streets[$i % count($this->streets)];
            $lat  = $area['lat'] + (($i % 200 - 100) / 10000);
            $lng  = $area['lng'] + (($i % 150 - 75)  / 10000);

            // Realistic VIP distribution
            $vip = match (true) {
                $i < 5  => 'platinum',
                $i < 20 => 'gold',
                $i < 60 => 'silver',
                default => 'standard',
            };

            $customers[] = Customer::create([
                'ulid'              => Str::ulid(),
                'merchant_id'       => $merchant->id,
                'customer_name'     => "{$biz} {$name} " . ($i + 1),
                'phone'             => '08' . str_pad($i + 200000000, 9, '0', STR_PAD_LEFT),
                'default_address'   => "{$st} No. " . ($i % 200 + 1) . ", {$area['name']}, Bandung",
                'default_latitude'  => round($lat, 7),
                'default_longitude' => round($lng, 7),
                'vip_level'         => $vip,
                'is_active'         => true,
            ]);
        }

        $this->command->info('250 customers created.');

        // ── Historical orders: 6 months back ─────────────────────────────────
        $this->command->info('Generating 6 months of delivery history...');

        $productList = array_keys($this->products);
        $productPrices = array_values($this->products);
        $orderCount  = 0;
        $today       = Carbon::today();

        // Each past day: 20–45 delivered orders, skip Sundays
        for ($daysAgo = 180; $daysAgo >= 1; $daysAgo--) {
            $date = $today->copy()->subDays($daysAgo);
            if ($date->isSunday()) continue;

            // Growth trend: more orders as time approaches today
            $baseOrders = 20 + (int)((180 - $daysAgo) / 180 * 25);
            $dailyCount = rand($baseOrders, $baseOrders + 8);

            for ($j = 0; $j < $dailyCount; $j++) {
                $cust    = $customers[($orderCount * 7 + $j * 3) % count($customers)];
                $prodIdx = ($orderCount + $j) % count($productList);
                $prod    = $productList[$prodIdx];
                $price   = $productPrices[$prodIdx];
                $qty     = rand(2, 10);
                $value   = $price * $qty;
                $driver  = $drivers[$j % count($drivers)];
                $pm      = $this->paymentMethods[$j % count($this->paymentMethods)];

                $createdAt   = $date->copy()->setTime(rand(6, 9), rand(0, 59));
                $assignedAt  = $createdAt->copy()->addMinutes(rand(10, 30));
                $pickedUpAt  = $assignedAt->copy()->addMinutes(rand(15, 45));
                $deliveredAt = $pickedUpAt->copy()->addMinutes(rand(20, 90));

                DeliveryOrder::create([
                    'ulid'                    => Str::ulid(),
                    'order_number'            => 'ORD-' . $date->format('Ymd') . '-' . str_pad($orderCount + 1, 5, '0', STR_PAD_LEFT),
                    'merchant_id'             => $merchant->id,
                    'driver_id'               => $driver->id,
                    'customer_id'             => $cust->id,
                    'customer_name'           => $cust->customer_name,
                    'customer_phone'          => $cust->phone,
                    'product_name'            => $prod,
                    'order_value'             => $value,
                    'delivery_address'        => $cust->default_address,
                    'delivery_latitude'       => $cust->default_latitude,
                    'delivery_longitude'      => $cust->default_longitude,
                    'requested_delivery_date' => $date->toDateString(),
                    'status'                  => 'delivered',
                    'payment_method'          => $pm,
                    'order_created_at'        => $createdAt,
                    'assigned_at'             => $assignedAt,
                    'picked_up_at'            => $pickedUpAt,
                    'delivered_at'            => $deliveredAt,
                    'created_by'              => $owner->id,
                ]);
                $orderCount++;
            }
        }

        $this->command->info("{$orderCount} historical orders created.");

        // ── Today: 35 pending orders for dispatch demo ────────────────────────
        $todayStr = $today->toDateString();
        $todayFmt = $today->format('Ymd');

        for ($k = 0; $k < 35; $k++) {
            $cust  = $customers[$k * 5 % count($customers)];
            $prod  = $productList[$k % count($productList)];
            $price = $productPrices[$k % count($productList)];
            $qty   = rand(2, 8);

            DeliveryOrder::create([
                'ulid'                    => Str::ulid(),
                'order_number'            => "ORD-{$todayFmt}-T" . str_pad($k + 1, 3, '0', STR_PAD_LEFT),
                'merchant_id'             => $merchant->id,
                'customer_id'             => $cust->id,
                'customer_name'           => $cust->customer_name,
                'customer_phone'          => $cust->phone,
                'product_name'            => $prod,
                'order_value'             => $price * $qty,
                'delivery_address'        => $cust->default_address,
                'delivery_latitude'       => $cust->default_latitude,
                'delivery_longitude'      => $cust->default_longitude,
                'requested_delivery_date' => $todayStr,
                'status'                  => 'pending',
                'payment_method'          => $this->paymentMethods[$k % count($this->paymentMethods)],
                'order_created_at'        => now()->subMinutes(rand(10, 120)),
                'created_by'              => $owner->id,
            ]);
        }

        $this->command->info('35 pending orders for today created.');
        $this->command->info('');
        $this->command->info('=== DEMO ACCOUNT READY ===');
        $this->command->info('Merchant : Ombar Distribusi');
        $this->command->info('Owner    : owner@ombar.id / password');
        $this->command->info('Dispatch : dispatcher@ombar.id / password');
        $this->command->info('Drivers  : driver1-5@ombar.id / password');
        $this->command->info('Total orders: ' . ($orderCount + 35));
    }
}
