<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\MerchantSubscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateTrialMerchant extends Command
{
    protected $signature = 'trial:create
                            {--company= : Company name (defaults to a demo name)}
                            {--email=   : Owner email address}
                            {--password=: Owner password (defaults to "demo1234")}
                            {--with-samples : Also seed sample customers and orders}';

    protected $description = 'Create a full trial merchant environment for demos and pilot testing';

    public function handle(): int
    {
        $company  = $this->option('company')  ?? 'Demo Distributor ' . Str::upper(Str::random(4));
        $email    = $this->option('email')    ?? 'owner@' . Str::slug($company) . '.demo';
        $password = $this->option('password') ?? 'demo1234';

        if (Merchant::where('email', $email)->exists()) {
            $this->error("A merchant with email {$email} already exists.");
            return self::FAILURE;
        }

        $this->info("Creating trial merchant: {$company}");

        DB::transaction(function () use ($company, $email, $password) {
            // 1. Merchant
            $merchant = Merchant::create([
                'ulid'         => (string) Str::ulid(),
                'company_name' => $company,
                'slug'         => Str::slug($company) . '-' . rand(1000, 9999),
                'address'      => 'Jl. Demo No. 1, Bandung',
                'phone'        => '08123456789',
                'email'        => $email,
                'timezone'     => 'Asia/Jakarta',
            ]);

            // 2. Merchant Settings
            MerchantSetting::create([
                'merchant_id'          => $merchant->id,
                'depot_address'        => 'Jl. Demo No. 1, Bandung',
                'depot_latitude'       => -6.9175,
                'depot_longitude'      => 107.6191,
                'routing_algorithm'    => 'balanced',
                'routing_mode'         => 'balanced',
                'max_stops_per_driver' => 20,
                'klotter_size'         => 10,
                'working_hours_start'  => '07:00:00',
                'working_hours_end'    => '17:00:00',
                'working_days'         => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
                'tracking_expiry_hours'=> 48,
                'public_tracking_enabled' => true,
                'driver_location_visible' => true,
                'batch_enforcement'    => true,
                'two_opt_enabled'      => true,
            ]);

            // 3. Feature flags
            foreach (['merchant_platform', 'customer_domain', 'business_intelligence'] as $feature) {
                MerchantFeature::create([
                    'merchant_id' => $merchant->id,
                    'feature'     => $feature,
                    'is_enabled'  => true,
                ]);
            }

            // 4. Trial subscription (30 days)
            MerchantSubscription::create([
                'merchant_id'  => $merchant->id,
                'status'       => 'trial',
                'started_at'   => now(),
                'expires_at'   => now()->addDays(30),
            ]);

            // 5. Owner account
            $owner = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => 'Pemilik Demo',
                'email'       => $email,
                'password'    => Hash::make($password),
                'role'        => 'merchant_owner',
                'is_active'   => true,
            ]);

            // 6. Dispatcher
            User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => 'Dispatcher Demo',
                'email'       => 'dispatcher@' . Str::slug($merchant->company_name) . '.demo',
                'password'    => Hash::make($password),
                'role'        => 'dispatcher',
                'is_active'   => true,
            ]);

            // 7. Sample Driver
            $driverUser = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => 'Budi Santoso',
                'email'       => 'driver@' . Str::slug($merchant->company_name) . '.demo',
                'password'    => Hash::make($password),
                'role'        => 'driver',
                'is_active'   => true,
            ]);

            Driver::create([
                'ulid'         => (string) Str::ulid(),
                'merchant_id'  => $merchant->id,
                'user_id'      => $driverUser->id,
                'driver_name'  => 'Budi Santoso',
                'phone'        => '08111222333',
                'vehicle_type' => 'motor',
                'vehicle_plate'=> 'D 1234 ABC',
                'status'       => 'available',
                'is_active'    => true,
            ]);

            // 8. Sample Branch
            MerchantBranch::create([
                'merchant_id'          => $merchant->id,
                'name'                 => 'Gudang Utama',
                'address'              => 'Jl. Demo No. 1, Bandung',
                'latitude'             => -6.9175,
                'longitude'            => 107.6191,
                'is_primary'           => true,
                'max_stops_per_driver' => 20,
            ]);

            // 9. Optional samples
            if ($this->option('with-samples')) {
                $this->seedSampleData($merchant, $password);
            }

            $this->line('');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Merchant ID',     $merchant->id],
                    ['Company',         $merchant->company_name],
                    ['Owner Email',     $email],
                    ['Password',        $password],
                    ['Dispatcher Email','dispatcher@' . Str::slug($merchant->company_name) . '.demo'],
                    ['Driver Email',    'driver@' . Str::slug($merchant->company_name) . '.demo'],
                    ['Trial Expires',   now()->addDays(30)->toDateString()],
                ]
            );
        });

        $this->info('Trial merchant created successfully.');
        $this->line('To delete: php artisan trial:delete --merchant-id=<id>');

        return self::SUCCESS;
    }

    private function seedSampleData(Merchant $merchant, string $password): void
    {
        $areas = [
            ['Cicendo', -6.9064, 107.5914],
            ['Coblong', -6.8959, 107.6077],
            ['Sukasari', -6.8729, 107.5887],
            ['Cidadap', -6.8628, 107.5938],
            ['Buah Batu', -6.9516, 107.6477],
        ];

        foreach ($areas as [$area, $lat, $lng]) {
            for ($i = 1; $i <= 3; $i++) {
                Customer::create([
                    'ulid'             => (string) Str::ulid(),
                    'merchant_id'      => $merchant->id,
                    'customer_name'    => "Pelanggan {$area} {$i}",
                    'phone'            => '0812' . rand(10000000, 99999999),
                    'default_address'  => "Jl. {$area} No. {$i}, Bandung",
                    'default_latitude' => $lat + (rand(-50, 50) / 10000),
                    'default_longitude'=> $lng + (rand(-50, 50) / 10000),
                    'location_source'  => 'manual_pin',
                    'vip_level'        => 'standard',
                    'is_active'        => true,
                ]);
            }
        }

        $customers = Customer::where('merchant_id', $merchant->id)->get();

        foreach ($customers->take(5) as $customer) {
            DeliveryOrder::create([
                'ulid'                    => (string) Str::ulid(),
                'merchant_id'             => $merchant->id,
                'customer_id'             => $customer->id,
                'order_number'            => 'ORD-DEMO-' . strtoupper(Str::random(6)),
                'customer_name'           => $customer->customer_name,
                'customer_phone'          => $customer->phone,
                'delivery_address'        => $customer->default_address,
                'delivery_latitude'       => $customer->default_latitude,
                'delivery_longitude'      => $customer->default_longitude,
                'product_name'            => 'Galon Air Mineral 19L',
                'order_value'             => rand(3, 10) * 20000,
                'status'                  => 'pending',
                'requested_delivery_date' => now()->format('Y-m-d'),
                'payment_method'          => 'cash',
                'order_created_at'        => now(),
            ]);
        }

        $this->line("  → Seeded 15 sample customers and 5 sample orders.");
    }
}
