<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\User;
use App\Models\VipConfig;
use App\Services\RoutingEngine\RoutingEngineService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private array $bandungAreas = [
        ['name' => 'Dago',          'lat' => -6.8901, 'lng' => 107.6090, 'radius' => 0.02],
        ['name' => 'Buah Batu',     'lat' => -6.9501, 'lng' => 107.6426, 'radius' => 0.025],
        ['name' => 'Antapani',      'lat' => -6.9141, 'lng' => 107.6638, 'radius' => 0.02],
        ['name' => 'Cicendo',       'lat' => -6.9013, 'lng' => 107.5899, 'radius' => 0.02],
        ['name' => 'Regol',         'lat' => -6.9268, 'lng' => 107.6106, 'radius' => 0.018],
        ['name' => 'Kiaracondong',  'lat' => -6.9276, 'lng' => 107.6478, 'radius' => 0.025],
        ['name' => 'Coblong',       'lat' => -6.8911, 'lng' => 107.6139, 'radius' => 0.02],
        ['name' => 'Sukasari',      'lat' => -6.8812, 'lng' => 107.5985, 'radius' => 0.02],
        ['name' => 'Bandung Wetan', 'lat' => -6.9007, 'lng' => 107.6234, 'radius' => 0.015],
        ['name' => 'Cibeunying',    'lat' => -6.9001, 'lng' => 107.6389, 'radius' => 0.022],
    ];

    private array $streetNames = [
        'Jl. Dago', 'Jl. Cihampelas', 'Jl. Setiabudhi', 'Jl. Pasteur',
        'Jl. Soekarno Hatta', 'Jl. Kiaracondong', 'Jl. Buah Batu',
        'Jl. Riau', 'Jl. Diponegoro', 'Jl. Laswi', 'Jl. Merdeka',
        'Jl. Braga', 'Jl. Asia Afrika', 'Jl. Lengkong', 'Jl. Gatot Subroto',
    ];

    private array $businessNames = [
        'Warung', 'Toko', 'Depot', 'Minimart', 'Kios', 'Warung Makan',
        'Kedai', 'Toko Kelontong', 'Warung Sembako', 'Agen',
    ];

    private array $firstNames = [
        'Budi', 'Sari', 'Ahmad', 'Dewi', 'Andi', 'Nina', 'Reza', 'Fitri',
        'Hendra', 'Yuli', 'Doni', 'Ratna', 'Rizki', 'Siti', 'Agus', 'Rina',
        'Farhan', 'Mega', 'Irwan', 'Tina', 'Wahyu', 'Lina', 'Bayu', 'Desi',
    ];

    private array $products = [
        'Susu Segar 1L', 'Susu Segar 5L', 'Susu UHT 250ml x12', 'Yogurt 500ml',
        'Keju 200g', 'Mentega 250g', 'Es Krim 1L', 'Frozen Beef 1kg',
        'Frozen Chicken 2kg', 'Sosis Ayam 500g', 'Nugget 500g', 'Bakso 1kg',
        'Telur Ayam 30pcs', 'Telur Bebek 20pcs', 'Susu Kambing 1L',
    ];

    private array $vipLevels = ['standard', 'standard', 'standard', 'standard', 'silver', 'silver', 'gold', 'platinum'];

    public function run(): void
    {
        $this->command->info('Seeding Hontal Delivery Platform...');

        $merchant = Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'Distributor Segar Bandung',
            'slug'         => 'distributor-segar-bandung',
            'address'      => 'Jl. Soekarno Hatta No. 123, Bandung',
            'phone'        => '022-1234567',
            'email'        => 'admin@segar.id',
            'timezone'     => 'Asia/Jakarta',
        ]);

        MerchantSetting::create([
            'merchant_id'          => $merchant->id,
            'depot_address'        => 'Jl. Soekarno Hatta No. 123, Bandung',
            'depot_latitude'       => -6.9344,
            'depot_longitude'      => 107.6278,
            'max_stops_per_driver' => 35,
            'working_hours_start'  => '07:00:00',
            'working_hours_end'    => '17:00:00',
            'routing_algorithm'    => 'scored',
        ]);

        foreach (['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200] as $level => $score) {
            VipConfig::create(['merchant_id' => $merchant->id, 'vip_level' => $level, 'score_value' => $score]);
        }

        User::create(['ulid' => Str::ulid(), 'name' => 'Super Admin', 'email' => 'admin@hontal.id', 'password' => Hash::make('password'), 'role' => 'super_admin', 'is_active' => true]);

        $owner      = User::create(['ulid' => Str::ulid(), 'merchant_id' => $merchant->id, 'name' => 'Pak Santoso',     'email' => 'owner@segar.id',      'password' => Hash::make('password'), 'role' => 'merchant_owner', 'is_active' => true]);
        $dispatcher = User::create(['ulid' => Str::ulid(), 'merchant_id' => $merchant->id, 'name' => 'Sari Dispatcher', 'email' => 'dispatcher@segar.id',  'password' => Hash::make('password'), 'role' => 'dispatcher',     'is_active' => true]);

        $driverInfos = [
            ['Andri Kurniawan', 'driver1@segar.id', 'D 1234 ABX', -6.9050, 107.6100],
            ['Bowo Susanto',    'driver2@segar.id', 'D 5678 CDX', -6.9200, 107.6350],
            ['Catur Wicaksono', 'driver3@segar.id', 'D 9012 EFX', -6.9400, 107.6500],
        ];

        $drivers = [];
        foreach ($driverInfos as $i => $di) {
            $du = User::create(['ulid' => Str::ulid(), 'merchant_id' => $merchant->id, 'name' => $di[0], 'email' => $di[1], 'password' => Hash::make('password'), 'role' => 'driver', 'is_active' => true]);
            $drivers[] = Driver::create([
                'ulid' => Str::ulid(), 'merchant_id' => $merchant->id, 'user_id' => $du->id,
                'driver_name' => $di[0], 'phone' => '0812-100' . ($i+1) . '-000' . ($i+1),
                'vehicle_type' => 'motorcycle', 'vehicle_plate' => $di[2],
                'status' => 'available', 'current_lat' => $di[3], 'current_lng' => $di[4],
                'last_seen' => now()->subMinutes(rand(1, 5)),
            ]);
        }

        $this->command->info('Merchant, users, and 3 drivers created.');

        // 300 customers
        $customers = [];
        for ($i = 0; $i < 300; $i++) {
            $area  = $this->bandungAreas[$i % count($this->bandungAreas)];
            $fn    = $this->firstNames[$i % count($this->firstNames)];
            $biz   = $this->businessNames[$i % count($this->businessNames)];
            $st    = $this->streetNames[$i % count($this->streetNames)];
            $vip   = $this->vipLevels[$i % count($this->vipLevels)];
            $lat   = $area['lat'] + (($i % 200 - 100) / 10000);
            $lng   = $area['lng'] + (($i % 150 - 75) / 10000);

            $customers[] = Customer::create([
                'ulid'              => Str::ulid(),
                'merchant_id'       => $merchant->id,
                'customer_name'     => "{$biz} {$fn} " . ($i + 1),
                'phone'             => '08' . str_pad($i + 100000000, 9, '0', STR_PAD_LEFT),
                'default_address'   => "{$st} No. " . ($i % 200 + 1) . ", {$area['name']}, Bandung",
                'default_latitude'  => round($lat, 7),
                'default_longitude' => round($lng, 7),
                'vip_level'         => $vip,
                'is_active'         => true,
            ]);
        }

        $this->command->info('300 customers created.');

        // 100 orders for today
        $timeWindows = [['08:00', '10:00'], ['09:00', '11:00'], ['10:00', '12:00'], ['11:00', '13:00'], ['13:00', '15:00'], [null, null]];
        $orders = [];
        $today  = now()->format('Ymd');

        for ($i = 0; $i < 100; $i++) {
            $cust   = $customers[$i * 3 % count($customers)];
            $prod   = $this->products[$i % count($this->products)];
            $win    = $timeWindows[$i % count($timeWindows)];
            $orders[] = DeliveryOrder::create([
                'ulid'                     => Str::ulid(),
                'order_number'             => "ORD-{$today}-" . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'merchant_id'              => $merchant->id,
                'customer_id'              => $cust->id,
                'customer_name'            => $cust->customer_name,
                'customer_phone'           => $cust->phone,
                'product_name'             => $prod,
                'order_value'              => (rand(5, 50) * 10000),
                'delivery_address'         => $cust->default_address,
                'delivery_latitude'        => $cust->default_latitude,
                'delivery_longitude'       => $cust->default_longitude,
                'requested_delivery_date'  => today()->toDateString(),
                'requested_delivery_start' => $win[0],
                'requested_delivery_end'   => $win[1],
                'status'                   => 'pending',
                'order_created_at'         => now()->subMinutes(rand(30, 480)),
                'created_by'               => $dispatcher->id,
            ]);
        }

        $this->command->info('100 delivery orders created for today.');

        // Run routing engine
        $this->command->info('Running Smart Routing V3...');
        try {
            $merchant->load('vipConfigs', 'settings');
            $engine = app(RoutingEngineService::class);
            $route  = $engine->generate(
                $merchant,
                collect($drivers)->pluck('id')->toArray(),
                collect($orders)->pluck('id')->toArray(),
                today()->toDateString()
            );
            $this->command->info("Route generated: {$route->total_stops} stops across {$route->total_drivers} drivers.");
        } catch (\Exception $e) {
            $this->command->warn('Routing failed: ' . $e->getMessage());
        }

        $this->command->info('');
        $this->command->info('Seed complete. Login: admin@hontal.id / owner@segar.id / dispatcher@segar.id / driver1-3@segar.id (all: password)');
    }
}
