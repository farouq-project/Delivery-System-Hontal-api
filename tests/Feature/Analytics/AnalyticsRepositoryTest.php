<?php

namespace Tests\Feature\Analytics;

use App\Analytics\AnalyticsRepository;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsRepository $repo;
    private Merchant $merchant;
    private int $merchantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(AnalyticsRepository::class);

        $this->merchant   = Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'BI Test Merchant',
            'slug'         => 'bi-test-' . rand(1000, 9999),
            'email'        => 'bi' . rand() . '@test.com',
        ]);
        $this->merchantId = $this->merchant->id;

        MerchantFeature::create([
            'merchant_id' => $this->merchantId,
            'feature'     => 'customer_domain',
            'is_enabled'  => true,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createCustomer(array $overrides = []): Customer
    {
        return Customer::withoutGlobalScope(MerchantScope::class)->create(array_merge([
            'ulid'            => Str::ulid(),
            'merchant_id'     => $this->merchantId,
            'customer_name'   => 'Customer ' . Str::random(6),
            'default_address' => 'Jl. Test',
            'is_active'       => true,
        ], $overrides));
    }

    private function insertOrder(array $fields): void
    {
        $base = [
            'ulid'             => Str::ulid(),
            'order_number'     => 'ORD-' . Str::random(8),
            'merchant_id'      => $this->merchantId,
            'customer_id'      => null,
            'customer_name'    => 'Customer',
            'product_name'     => 'Product',
            'order_value'      => 100_000,
            'delivery_address' => 'Jl. Test',
            'status'           => 'pending',
            'order_created_at' => now(),
            'delivered_at'     => null,
            'failed_at'        => null,
            'deleted_at'       => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ];
        DB::table('delivery_orders')->insert(array_merge($base, $fields));
    }

    private function createDriver(array $overrides = []): Driver
    {
        $user = User::create([
            'ulid'        => Str::ulid(),
            'merchant_id' => $this->merchantId,
            'name'        => 'Driver User',
            'email'       => Str::random(8) . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => 'driver',
            'is_active'   => true,
        ]);
        return Driver::withoutGlobalScope(MerchantScope::class)->create(array_merge([
            'ulid'        => Str::ulid(),
            'merchant_id' => $this->merchantId,
            'user_id'     => $user->id,
            'driver_name' => 'Test Driver',
            'phone'         => '08' . rand(100000000, 999999999),
            'vehicle_plate' => 'B ' . rand(1000, 9999) . ' XX',
            'status'      => 'available',
            'is_active'   => true,
        ], $overrides));
    }

    // ── Revenue ───────────────────────────────────────────────────────────────

    public function test_delivered_revenue_for_day_sums_correctly(): void
    {
        $today = Carbon::today();
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_value' => 200_000]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_value' => 300_000]);
        $this->insertOrder(['status' => 'failed',    'delivered_at' => null]);

        $result = $this->repo->deliveredRevenueForDay($this->merchantId, $today);

        $this->assertSame(2, $result['count']);
        $this->assertEqualsWithDelta(500_000, $result['revenue'], 0.01);
    }

    public function test_delivered_revenue_for_period_sums_correctly(): void
    {
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now()->endOfMonth();
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $from->copy()->addDay(), 'order_value' => 150_000]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $from->copy()->addDays(5), 'order_value' => 250_000]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $from->copy()->subDay(), 'order_value' => 999_999]); // outside range

        $result = $this->repo->deliveredRevenueForPeriod($this->merchantId, $from, $to);

        $this->assertSame(2, $result['count']);
        $this->assertEqualsWithDelta(400_000, $result['revenue'], 0.01);
    }

    public function test_delivered_revenue_returns_zero_when_no_orders(): void
    {
        $result = $this->repo->deliveredRevenueForDay($this->merchantId, Carbon::today());
        $this->assertSame(0, $result['count']);
        $this->assertSame(0.0, $result['revenue']);
    }

    // ── Order Counts ──────────────────────────────────────────────────────────

    public function test_order_count_for_day(): void
    {
        $today     = Carbon::today();
        $yesterday = Carbon::yesterday();
        $this->insertOrder(['order_created_at' => $today]);
        $this->insertOrder(['order_created_at' => $today]);
        $this->insertOrder(['order_created_at' => $yesterday]); // excluded

        $this->assertSame(2, $this->repo->orderCountForDay($this->merchantId, $today));
    }

    public function test_order_count_for_period(): void
    {
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now()->endOfMonth();
        $this->insertOrder(['order_created_at' => $from->copy()->addDay()]);
        $this->insertOrder(['order_created_at' => $from->copy()->addDays(10)]);
        $this->insertOrder(['order_created_at' => $from->copy()->subDay()]); // excluded

        $this->assertSame(2, $this->repo->orderCountForPeriod($this->merchantId, $from, $to));
    }

    // ── Terminal Counts ───────────────────────────────────────────────────────

    public function test_terminal_counts_for_day(): void
    {
        $today = Carbon::today();
        $this->insertOrder(['status' => 'delivered',  'order_created_at' => $today]);
        $this->insertOrder(['status' => 'delivered',  'order_created_at' => $today]);
        $this->insertOrder(['status' => 'failed',     'order_created_at' => $today]);
        $this->insertOrder(['status' => 'cancelled',  'order_created_at' => $today]);
        $this->insertOrder(['status' => 'pending',    'order_created_at' => $today]); // not terminal

        $result = $this->repo->terminalCountsForDay($this->merchantId, $today);

        $this->assertSame(4, $result['total']);
        $this->assertSame(2, $result['delivered']);
    }

    // ── Drivers ───────────────────────────────────────────────────────────────

    public function test_active_driver_count(): void
    {
        $this->createDriver(['status' => 'available']);
        $this->createDriver(['status' => 'delivering']);
        $this->createDriver(['status' => 'delivering']);
        $this->createDriver(['status' => 'offline']); // not active

        $this->assertSame(3, $this->repo->activeDriverCount($this->merchantId));
    }

    public function test_offline_active_drivers(): void
    {
        $this->createDriver(['status' => 'offline',   'is_active' => true]);
        $this->createDriver(['status' => 'offline',   'is_active' => false]); // inactive — excluded
        $this->createDriver(['status' => 'available', 'is_active' => true]);  // not offline — excluded

        $result = $this->repo->offlineActiveDrivers($this->merchantId);
        $this->assertCount(1, $result);
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    public function test_total_customers(): void
    {
        $this->createCustomer();
        $this->createCustomer();

        $this->assertSame(2, $this->repo->totalCustomers($this->merchantId));
    }

    public function test_new_customers_for_period(): void
    {
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now()->endOfMonth();

        // Use DB::table to control created_at — Eloquent overwrites it automatically
        DB::table('customers')->insert([
            'ulid' => Str::ulid(), 'merchant_id' => $this->merchantId,
            'customer_name' => 'New A', 'default_address' => 'Jl. A',
            'is_active' => 1,
            'created_at' => $from->copy()->addDay(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'ulid' => Str::ulid(), 'merchant_id' => $this->merchantId,
            'customer_name' => 'Old', 'default_address' => 'Jl. B',
            'is_active' => 1,
            'created_at' => $from->copy()->subDay(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->repo->newCustomersForPeriod($this->merchantId, $from, $to));
    }

    public function test_repeat_customer_count_from_orders(): void
    {
        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();

        // c1 has 2 delivered orders — repeat
        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now()]);
        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now()]);
        // c2 has 1 delivered order — not repeat
        $this->insertOrder(['customer_id' => $c2->id, 'status' => 'delivered', 'delivered_at' => now()]);

        $this->assertSame(1, $this->repo->repeatCustomerCount($this->merchantId));
    }

    public function test_repeat_customer_count_from_profiles(): void
    {
        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();

        // Listener auto-creates profiles; use updateOrCreate to set values
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c1->id], ['merchant_id' => $this->merchantId, 'total_orders' => 5]);
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c2->id], ['merchant_id' => $this->merchantId, 'total_orders' => 1]);

        $this->assertSame(1, $this->repo->repeatCustomerCountFromProfiles($this->merchantId));
    }

    public function test_dormant_customer_count_from_profiles(): void
    {
        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();
        $c3 = $this->createCustomer();

        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c1->id], ['merchant_id' => $this->merchantId, 'health_status' => 'dormant']);
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c2->id], ['merchant_id' => $this->merchantId, 'health_status' => 'lost']);
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c3->id], ['merchant_id' => $this->merchantId, 'health_status' => 'healthy']);

        $this->assertSame(2, $this->repo->dormantCustomerCountFromProfiles($this->merchantId));
    }

    public function test_customers_without_gps_count(): void
    {
        $this->createCustomer(['default_latitude' => null,  'is_active' => true]);
        $this->createCustomer(['default_latitude' => -6.2,  'is_active' => true]);  // has GPS
        $this->createCustomer(['default_latitude' => null,  'is_active' => false]); // inactive

        $this->assertSame(1, $this->repo->customersWithoutGpsCount($this->merchantId));
    }

    // ── Merchant Isolation ────────────────────────────────────────────────────

    public function test_merchant_isolation_for_order_counts(): void
    {
        $other = Merchant::create([
            'ulid' => Str::ulid(), 'company_name' => 'Other', 'slug' => 'other-' . rand(),
            'email' => 'other' . rand() . '@test.com',
        ]);

        $this->insertOrder(['merchant_id' => $this->merchantId, 'order_created_at' => now()]);
        $this->insertOrder(['merchant_id' => $other->id,        'order_created_at' => now()]);

        $this->assertSame(1, $this->repo->orderCountForDay($this->merchantId, Carbon::today()));
        $this->assertSame(1, $this->repo->orderCountForDay($other->id, Carbon::today()));
    }

    // ── Requires Attention ────────────────────────────────────────────────────

    public function test_delayed_delivery_count(): void
    {
        $fiveHoursAgo = now()->subHours(5);
        $oneHourAgo   = now()->subHour();

        // Use 'assigned' for both delayed orders (in_transit may not be a valid status in test DB)
        $this->insertOrder(['status' => 'assigned', 'order_created_at' => $fiveHoursAgo]); // delayed
        $this->insertOrder(['status' => 'assigned', 'order_created_at' => $fiveHoursAgo]); // delayed
        $this->insertOrder(['status' => 'assigned', 'order_created_at' => $oneHourAgo]);   // not delayed
        $this->insertOrder(['status' => 'delivered', 'order_created_at' => $fiveHoursAgo]); // terminal

        $this->assertSame(2, $this->repo->delayedDeliveryCount($this->merchantId));
    }

    public function test_failed_today_count(): void
    {
        $today     = Carbon::today();
        $yesterday = Carbon::yesterday();

        $this->insertOrder(['status' => 'failed', 'failed_at' => $today]);
        $this->insertOrder(['status' => 'failed', 'failed_at' => $today]);
        $this->insertOrder(['status' => 'failed', 'failed_at' => $yesterday]); // not today

        $this->assertSame(2, $this->repo->failedTodayCount($this->merchantId));
    }
}
