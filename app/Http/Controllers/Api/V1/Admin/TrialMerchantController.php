<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantFeature;
use App\Models\MerchantSetting;
use App\Models\MerchantSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TrialMerchantController extends Controller
{
    public function create(Request $request)
    {
        $data = $request->validate([
            'company_name'  => 'required|string|max:100',
            'owner_email'   => 'required|email|unique:users,email',
            'owner_name'    => 'required|string|max:100',
            'password'      => 'required|string|min:8',
            'phone'         => 'nullable|string|max:20',
            'timezone'      => 'nullable|string|max:50',
            'with_samples'  => 'boolean',
            'trial_days'    => 'nullable|integer|min:1|max:365',
        ]);

        $trialDays = $data['trial_days'] ?? 30;
        $result    = [];

        DB::transaction(function () use ($data, $trialDays, &$result) {
            $merchant = Merchant::create([
                'ulid'         => (string) Str::ulid(),
                'company_name' => $data['company_name'],
                'slug'         => Str::slug($data['company_name']) . '-' . rand(1000, 9999),
                'address'      => 'Belum diisi',
                'phone'        => $data['phone'] ?? '',
                'email'        => $data['owner_email'],
                'timezone'     => $data['timezone'] ?? 'Asia/Jakarta',
            ]);

            MerchantSetting::create([
                'merchant_id'             => $merchant->id,
                'depot_latitude'          => -6.9175,
                'depot_longitude'         => 107.6191,
                'routing_algorithm'       => 'balanced',
                'routing_mode'            => 'balanced',
                'max_stops_per_driver'    => 20,
                'klotter_size'            => 10,
                'working_hours_start'     => '07:00:00',
                'working_hours_end'       => '17:00:00',
                'tracking_expiry_hours'   => 48,
                'public_tracking_enabled' => true,
                'driver_location_visible' => true,
                'batch_enforcement'       => true,
                'two_opt_enabled'         => true,
            ]);

            foreach (['merchant_platform', 'customer_domain', 'business_intelligence'] as $feature) {
                MerchantFeature::create([
                    'merchant_id' => $merchant->id,
                    'feature'     => $feature,
                    'is_enabled'  => true,
                ]);
            }

            MerchantSubscription::create([
                'merchant_id'   => $merchant->id,
                'status'        => 'trial',
                'started_at'    => now(),
                'trial_ends_at' => now()->addDays($trialDays),
                'expires_at'    => now()->addDays($trialDays),
            ]);

            $owner = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => $data['owner_name'],
                'email'       => $data['owner_email'],
                'password'    => Hash::make($data['password']),
                'role'        => 'merchant_owner',
                'is_active'   => true,
            ]);

            $slug = Str::slug($merchant->company_name);
            $dispatcher = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => 'Dispatcher',
                'email'       => "dispatcher@{$slug}.demo",
                'password'    => Hash::make($data['password']),
                'role'        => 'dispatcher',
                'is_active'   => true,
            ]);

            $driverUser = User::create([
                'ulid'        => (string) Str::ulid(),
                'merchant_id' => $merchant->id,
                'name'        => 'Pengemudi Contoh',
                'email'       => "driver@{$slug}.demo",
                'password'    => Hash::make($data['password']),
                'role'        => 'driver',
                'is_active'   => true,
            ]);

            $driver = Driver::create([
                'ulid'         => (string) Str::ulid(),
                'merchant_id'  => $merchant->id,
                'user_id'      => $driverUser->id,
                'driver_name'  => 'Pengemudi Contoh',
                'phone'        => '08111222333',
                'vehicle_type' => 'motorcycle',
                'vehicle_plate'=> 'D 0000 XYZ',
                'status'       => 'available',
                'is_active'    => true,
            ]);

            MerchantBranch::create([
                'merchant_id' => $merchant->id,
                'name'        => 'Gudang Utama',
                'address'     => 'Belum diisi',
                'is_primary'  => true,
            ]);

            $sampleCount = ['customers' => 0, 'orders' => 0];
            if (!empty($data['with_samples'])) {
                $sampleCount = $this->seedSamples($merchant);
            }

            $result = [
                'merchant'          => $merchant->only(['id', 'ulid', 'company_name', 'email', 'slug']),
                'owner_email'       => $owner->email,
                'dispatcher_email'  => $dispatcher->email,
                'driver_email'      => $driverUser->email,
                'password'          => $data['password'],
                'trial_expires_at'  => now()->addDays($trialDays)->toISOString(),
                'sample_customers'  => $sampleCount['customers'],
                'sample_orders'     => $sampleCount['orders'],
            ];
        });

        return response()->json(['data' => $result], 201);
    }

    public function destroy(Request $request, Merchant $merchant)
    {
        // Only allow deleting trial merchants (email contains .demo or subscription is trial)
        $isTrial = str_contains($merchant->email, '.demo')
            || $merchant->subscription?->status === 'trial';

        if (!$isTrial) {
            return response()->json([
                'message' => 'Only trial merchants can be deleted via this endpoint. Use the database for production merchants.',
            ], 422);
        }

        DB::transaction(function () use ($merchant) {
            DB::table('route_stops')->whereIn('route_id', function ($q) use ($merchant) {
                $q->select('id')->from('routes')->where('merchant_id', $merchant->id);
            })->delete();
            DB::table('route_assignments')->whereIn('route_id', function ($q) use ($merchant) {
                $q->select('id')->from('routes')->where('merchant_id', $merchant->id);
            })->delete();
            DB::table('routes')->where('merchant_id', $merchant->id)->delete();
            DB::table('delivery_orders')->where('merchant_id', $merchant->id)->delete();
            DB::table('customer_profiles')->whereIn('customer_id', function ($q) use ($merchant) {
                $q->select('id')->from('customers')->where('merchant_id', $merchant->id);
            })->delete();
            DB::table('customers')->where('merchant_id', $merchant->id)->delete();
            DB::table('drivers')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_branches')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_settings')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_features')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_subscriptions')->where('merchant_id', $merchant->id)->delete();
            DB::table('merchant_activity_log')->where('merchant_id', $merchant->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', function ($q) use ($merchant) {
                    $q->select('id')->from('users')->where('merchant_id', $merchant->id);
                })->delete();
            DB::table('users')->where('merchant_id', $merchant->id)->delete();
            $merchant->forceDelete();
        });

        return response()->json(null, 204);
    }

    private function seedSamples(Merchant $merchant): array
    {
        $areas = [
            ['Cicendo',   -6.9064, 107.5914],
            ['Coblong',   -6.8959, 107.6077],
            ['Sukasari',  -6.8729, 107.5887],
            ['Buah Batu', -6.9516, 107.6477],
            ['Antapani',  -6.9214, 107.6620],
        ];

        $customers = [];
        foreach ($areas as [$area, $lat, $lng]) {
            for ($i = 1; $i <= 3; $i++) {
                $customers[] = Customer::create([
                    'ulid'              => (string) Str::ulid(),
                    'merchant_id'       => $merchant->id,
                    'customer_name'     => "Pelanggan {$area} {$i}",
                    'phone'             => '0812' . rand(10000000, 99999999),
                    'default_address'   => "Jl. {$area} No. {$i}, Bandung",
                    'default_latitude'  => $lat + (rand(-50, 50) / 10000),
                    'default_longitude' => $lng + (rand(-50, 50) / 10000),
                    'location_source'   => 'manual_pin',
                    'vip_level'         => 'standard',
                    'is_active'         => true,
                ]);
            }
        }

        $orderCount = 0;
        foreach (array_slice($customers, 0, 8) as $customer) {
            DeliveryOrder::create([
                'ulid'                    => (string) Str::ulid(),
                'merchant_id'             => $merchant->id,
                'customer_id'             => $customer->id,
                'order_number'            => 'ORD-' . strtoupper(Str::random(8)),
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
            $orderCount++;
        }

        return ['customers' => count($customers), 'orders' => $orderCount];
    }
}
