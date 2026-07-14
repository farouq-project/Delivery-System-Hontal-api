<?php

namespace Tests\Feature\Analytics;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\Scopes\MerchantScope;
use App\Models\User;
use App\Services\BusinessMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private BusinessMetricsService $service;
    private Merchant $merchant;
    private int $merchantId;
    private string $tz = 'Asia/Jakarta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = app(BusinessMetricsService::class);
        $this->merchant   = Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'Metrics Test Merchant',
            'slug'         => 'metrics-test-' . rand(1000, 9999),
            'email'        => 'metrics' . rand() . '@test.com',
        ]);
        $this->merchantId = $this->merchant->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enableDomain(): void
    {
        MerchantFeature::create([
            'merchant_id' => $this->merchantId,
            'feature'     => 'customer_domain',
            'is_enabled'  => true,
        ]);
    }

    private function insertOrder(array $fields): void
    {
        DB::table('delivery_orders')->insert(array_merge([
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
        ], $fields));
    }

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

    private function createDriver(array $overrides = []): Driver
    {
        $user = User::create([
            'ulid'        => Str::ulid(),
            'merchant_id' => $this->merchantId,
            'name'        => 'Driver',
            'email'       => Str::random(8) . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => 'driver',
            'is_active'   => true,
        ]);
        return Driver::withoutGlobalScope(MerchantScope::class)->create(array_merge([
            'ulid'        => Str::ulid(),
            'merchant_id' => $this->merchantId,
            'user_id'     => $user->id,
            'driver_name' => 'Driver',
            'phone'         => '08' . rand(100000000, 999999999),
            'vehicle_plate' => 'B ' . rand(1000, 9999) . ' XX',
            'status'      => 'available',
            'is_active'   => true,
        ], $overrides));
    }

    // ── getOperationsToday ────────────────────────────────────────────────────

    public function test_operations_today_revenue_and_counts(): void
    {
        $today = Carbon::today($this->tz);

        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_value' => 150_000, 'order_created_at' => $today]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_value' => 250_000, 'order_created_at' => $today]);
        $this->insertOrder(['status' => 'pending',   'order_created_at' => $today]);

        $result = $this->service->getOperationsToday($this->merchantId, $this->tz);

        $this->assertEqualsWithDelta(400_000, $result['revenue'], 0.01);
        $this->assertSame(3, $result['orders']);
        $this->assertSame(2, $result['deliveries_completed']);
    }

    public function test_operations_today_success_rate(): void
    {
        $today = Carbon::today($this->tz);

        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_created_at' => $today]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_created_at' => $today]);
        $this->insertOrder(['status' => 'failed',    'order_created_at' => $today, 'failed_at' => $today]);
        $this->insertOrder(['status' => 'cancelled', 'order_created_at' => $today]);

        $result = $this->service->getOperationsToday($this->merchantId, $this->tz);

        $this->assertEqualsWithDelta(50.0, $result['success_rate'], 0.01);
    }

    public function test_operations_today_success_rate_is_null_when_no_terminal_orders(): void
    {
        $today = Carbon::today($this->tz);
        $this->insertOrder(['status' => 'pending', 'order_created_at' => $today]);

        $result = $this->service->getOperationsToday($this->merchantId, $this->tz);

        $this->assertNull($result['success_rate']);
    }

    public function test_operations_today_active_driver_count(): void
    {
        $this->createDriver(['status' => 'available']);
        $this->createDriver(['status' => 'delivering']);
        $this->createDriver(['status' => 'offline']);

        $result = $this->service->getOperationsToday($this->merchantId, $this->tz);

        $this->assertSame(2, $result['active_drivers']);
    }

    // ── getBusinessThisMonth ──────────────────────────────────────────────────

    public function test_business_this_month_revenue(): void
    {
        $from = Carbon::now($this->tz)->startOfMonth()->addDay();

        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $from, 'order_value' => 500_000]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $from, 'order_value' => 300_000]);
        $this->insertOrder(['status' => 'pending']);

        $result = $this->service->getBusinessThisMonth($this->merchantId, $this->tz);

        $this->assertEqualsWithDelta(800_000, $result['revenue'], 0.01);
        $this->assertEqualsWithDelta(400_000, $result['avg_order_value'], 1.0);
    }

    public function test_business_this_month_new_customers_and_growth(): void
    {
        $from     = Carbon::now($this->tz)->startOfMonth();
        $lastFrom = Carbon::now($this->tz)->subMonth()->startOfMonth();

        // 2 new this month, 1 last month — use DB::table to control created_at
        DB::table('customers')->insert([
            'ulid' => Str::ulid(), 'merchant_id' => $this->merchantId,
            'customer_name' => 'New A', 'default_address' => 'Jl. A', 'is_active' => 1,
            'created_at' => $from->copy()->addDay(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'ulid' => Str::ulid(), 'merchant_id' => $this->merchantId,
            'customer_name' => 'New B', 'default_address' => 'Jl. B', 'is_active' => 1,
            'created_at' => $from->copy()->addDays(2), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'ulid' => Str::ulid(), 'merchant_id' => $this->merchantId,
            'customer_name' => 'Last Month', 'default_address' => 'Jl. C', 'is_active' => 1,
            'created_at' => $lastFrom->copy()->addDay(), 'updated_at' => now(),
        ]);

        $result = $this->service->getBusinessThisMonth($this->merchantId, $this->tz);

        $this->assertSame(2, $result['new_customers']);
        $this->assertEqualsWithDelta(100.0, $result['customer_growth_pct'], 0.01);
    }

    public function test_business_this_month_repeat_customers(): void
    {
        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();

        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now()]);
        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now()]);
        $this->insertOrder(['customer_id' => $c2->id, 'status' => 'delivered', 'delivered_at' => now()]);

        $result = $this->service->getBusinessThisMonth($this->merchantId, $this->tz);

        $this->assertSame(1, $result['repeat_customers']);
    }

    // ── getCustomerHealth ─────────────────────────────────────────────────────

    public function test_customer_health_without_domain_feature(): void
    {
        $this->createCustomer();
        $this->createCustomer();

        $result = $this->service->getCustomerHealth($this->merchantId, $this->tz);

        $this->assertSame(2, $result['total']);
        $this->assertNull($result['repeat']);
        $this->assertNull($result['dormant']);
    }

    public function test_customer_health_with_domain_feature(): void
    {
        $this->enableDomain();

        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();

        // Listener auto-creates profiles on customer creation; use updateOrCreate
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c1->id], ['merchant_id' => $this->merchantId, 'total_orders' => 5, 'health_status' => 'healthy']);
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->updateOrCreate(['customer_id' => $c2->id], ['merchant_id' => $this->merchantId, 'total_orders' => 1, 'health_status' => 'dormant']);

        $result = $this->service->getCustomerHealth($this->merchantId, $this->tz);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['repeat']);
        $this->assertSame(1, $result['dormant']);
    }

    // ── getClusterSummary ─────────────────────────────────────────────────────

    public function test_cluster_summary_groups_by_cluster(): void
    {
        $c1 = $this->createCustomer(['cluster' => 'Selatan']);
        $c2 = $this->createCustomer(['cluster' => 'Utara']);

        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now(), 'order_value' => 200_000]);
        $this->insertOrder(['customer_id' => $c2->id, 'status' => 'delivered', 'delivered_at' => now(), 'order_value' => 100_000]);

        $result = $this->service->getClusterSummary($this->merchantId);

        $this->assertCount(2, $result);
        // Sorted by revenue desc — Selatan should be first
        $this->assertSame('Selatan', $result[0]['cluster']);
        $this->assertEqualsWithDelta(200_000, $result[0]['revenue'], 0.01);
    }

    public function test_cluster_summary_success_rate_calculation(): void
    {
        $c = $this->createCustomer(['cluster' => 'Barat']);

        $this->insertOrder(['customer_id' => $c->id, 'status' => 'delivered', 'delivered_at' => now()]);
        $this->insertOrder(['customer_id' => $c->id, 'status' => 'delivered', 'delivered_at' => now()]);
        $this->insertOrder(['customer_id' => $c->id, 'status' => 'failed',    'failed_at' => now()]);
        $this->insertOrder(['customer_id' => $c->id, 'status' => 'cancelled']);

        $result = $this->service->getClusterSummary($this->merchantId);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(50.0, $result[0]['success_rate'], 0.01);
    }

    // ── getRecentActivity ─────────────────────────────────────────────────────

    public function test_recent_activity_returns_active_and_terminal_orders(): void
    {
        $this->insertOrder(['status' => 'delivered',  'updated_at' => now()]);
        $this->insertOrder(['status' => 'failed',     'updated_at' => now()]);
        $this->insertOrder(['status' => 'assigned',   'updated_at' => now()]);
        $this->insertOrder(['status' => 'pending',    'updated_at' => now()]); // excluded

        $result = $this->service->getRecentActivity($this->merchantId);

        $this->assertCount(3, $result);
    }

    public function test_recent_activity_contains_expected_keys(): void
    {
        $this->insertOrder(['status' => 'delivered', 'updated_at' => now()]);

        $result = $this->service->getRecentActivity($this->merchantId);

        $this->assertArrayHasKey('id',            $result[0]);
        $this->assertArrayHasKey('order_number',  $result[0]);
        $this->assertArrayHasKey('customer_name', $result[0]);
        $this->assertArrayHasKey('status',        $result[0]);
        $this->assertArrayHasKey('driver_name',   $result[0]);
        $this->assertArrayHasKey('occurred_at',   $result[0]);
    }

    // ── getRequiresAttention ──────────────────────────────────────────────────

    public function test_requires_attention_flags_delayed_deliveries(): void
    {
        $this->insertOrder(['status' => 'assigned', 'order_created_at' => now()->subHours(5)]);

        $result = $this->service->getRequiresAttention($this->merchantId);

        $types = array_column($result, 'type');
        $this->assertContains('delayed_deliveries', $types);
    }

    public function test_requires_attention_flags_failed_today(): void
    {
        $this->insertOrder(['status' => 'failed', 'failed_at' => Carbon::today()]);

        $result = $this->service->getRequiresAttention($this->merchantId);

        $types = array_column($result, 'type');
        $this->assertContains('failed_today', $types);
    }

    public function test_requires_attention_flags_customers_without_gps(): void
    {
        $this->createCustomer(['default_latitude' => null, 'is_active' => true]);

        $result = $this->service->getRequiresAttention($this->merchantId);

        $types = array_column($result, 'type');
        $this->assertContains('missing_gps', $types);
    }

    public function test_requires_attention_is_empty_when_no_issues(): void
    {
        $result = $this->service->getRequiresAttention($this->merchantId);
        $this->assertEmpty($result);
    }

    // ── Merchant Isolation ────────────────────────────────────────────────────

    public function test_metrics_are_isolated_per_merchant(): void
    {
        $other = Merchant::create([
            'ulid' => Str::ulid(), 'company_name' => 'Other Merchant',
            'slug' => 'other-' . rand(), 'email' => 'other' . rand() . '@test.com',
        ]);

        $today = Carbon::today($this->tz);
        // Order for this merchant
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => $today, 'order_value' => 500_000, 'order_created_at' => $today]);
        // Order for other merchant
        DB::table('delivery_orders')->insert([
            'ulid' => Str::ulid(), 'order_number' => 'ORD-OTHER',
            'merchant_id' => $other->id, 'customer_name' => 'Other',
            'product_name' => 'Product',
            'order_value' => 999_999, 'delivery_address' => 'Jl. Other',
            'status' => 'delivered', 'delivered_at' => $today,
            'order_created_at' => $today,
            'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->getOperationsToday($this->merchantId, $this->tz);

        $this->assertEqualsWithDelta(500_000, $result['revenue'], 0.01);
        $this->assertSame(1, $result['orders']);
    }

    // ── getRevenueForPeriod ───────────────────────────────────────────────────

    public function test_get_revenue_for_period(): void
    {
        $from = Carbon::now()->subDays(7);
        $to   = Carbon::now();

        $this->insertOrder(['status' => 'delivered', 'delivered_at' => now()->subDays(3), 'order_value' => 200_000]);
        $this->insertOrder(['status' => 'delivered', 'delivered_at' => now()->subDays(10), 'order_value' => 999_000]); // outside range

        $result = $this->service->getRevenueForPeriod($this->merchantId, $from, $to);

        $this->assertEqualsWithDelta(200_000, $result, 0.01);
    }

    // ── getTopCustomers ───────────────────────────────────────────────────────

    public function test_get_top_customers_sorted_by_spending(): void
    {
        $c1 = $this->createCustomer();
        $c2 = $this->createCustomer();

        $this->insertOrder(['customer_id' => $c1->id, 'status' => 'delivered', 'delivered_at' => now(), 'order_value' => 500_000]);
        $this->insertOrder(['customer_id' => $c2->id, 'status' => 'delivered', 'delivered_at' => now(), 'order_value' => 200_000]);

        $result = $this->service->getTopCustomers($this->merchantId);

        $this->assertCount(2, $result);
        $this->assertSame($c1->id, $result[0]['customer_id']);
        $this->assertEqualsWithDelta(500_000, $result[0]['total_spending'], 0.01);
    }
}
