<?php

namespace Tests\Feature\Commands;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\Scopes\MerchantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RebuildCustomerDomainTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createMerchant(): Merchant
    {
        return Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'Test Merchant ' . rand(1000, 9999),
            'slug'         => 'test-merchant-' . rand(1000, 9999),
            'email'        => 'merchant' . rand(1000, 9999) . '@test.com',
        ]);
    }

    private function enableCustomerDomain(Merchant $merchant): void
    {
        MerchantFeature::create([
            'merchant_id' => $merchant->id,
            'feature'     => 'customer_domain',
            'is_enabled'  => true,
        ]);
    }

    /**
     * Create a customer. If customer_domain is already enabled for the merchant,
     * the InitializeCustomerProfile listener will auto-create the profile row.
     */
    private function createCustomer(Merchant $merchant, array $overrides = []): Customer
    {
        return Customer::withoutGlobalScope(MerchantScope::class)->create(array_merge([
            'ulid'            => Str::ulid(),
            'merchant_id'     => $merchant->id,
            'customer_name'   => 'Test Customer ' . rand(1000, 9999),
            'default_address' => 'Jl. Test No. 1',
        ], $overrides));
    }

    /**
     * Force a specific profile state for test setup. Uses updateOrCreate so it
     * works whether or not the auto-listener has already created the row.
     */
    private function forceProfile(Customer $customer, array $values): void
    {
        CustomerProfile::withoutGlobalScope(MerchantScope::class)->updateOrCreate(
            ['customer_id' => $customer->id],
            array_merge(['merchant_id' => $customer->merchant_id], $values)
        );
    }

    private function getProfile(Customer $customer): CustomerProfile
    {
        return CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->firstOrFail();
    }

    private function createOrder(Customer $customer, array $overrides = []): DeliveryOrder
    {
        return DeliveryOrder::create(array_merge([
            'ulid'             => Str::ulid(),
            'order_number'     => 'ORD-TEST-' . rand(10000, 99999),
            'merchant_id'      => $customer->merchant_id,
            'customer_id'      => $customer->id,
            'customer_name'    => $customer->customer_name,
            'product_name'     => 'Test Product',
            'delivery_address' => 'Jl. Test No. 1',
            'status'           => 'delivered',
            'order_value'      => 100000,
            'order_created_at' => now()->subDays(5),
            'delivered_at'     => now()->subDays(5)->addHours(2),
        ], $overrides));
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    /** Historical customer: has orders but profile was never populated */
    public function test_rebuilds_profile_for_customer_with_historical_orders(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);  // profile auto-created (zeros)

        // Force the stale state: profile shows 0 even though orders exist
        $this->forceProfile($customer, ['total_orders' => 0, 'total_spending' => 0]);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 100000]);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 200000]);
        $this->createOrder($customer, ['status' => 'failed',    'order_value' => 0]);

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(3, $profile->total_orders);
        $this->assertEquals(2, $profile->total_deliveries);
        $this->assertEquals(1, $profile->total_failed);
        $this->assertEquals(300000.0, $profile->total_spending);
        $this->assertEquals(150000.0, $profile->avg_order_value);
    }

    /** Customer with no orders — profile resets cleanly to zeros */
    public function test_customer_without_orders_gets_zeroed_profile(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);

        // Artificially inflate the profile as if stale data was there
        $this->forceProfile($customer, ['total_orders' => 5, 'total_spending' => 500000]);

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(0, $profile->total_orders);
        $this->assertEquals(0.0, $profile->total_spending);
        $this->assertNull($profile->last_order_at);
    }

    /** Soft-deleted orders must NOT count toward the profile */
    public function test_soft_deleted_orders_excluded_from_profile(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 100000]);

        $deleted = $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 999999]);
        $deleted->delete();

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(1, $profile->total_orders);
        $this->assertEquals(100000.0, $profile->total_spending);
    }

    /** Restored orders must count after rebuild */
    public function test_restored_order_counts_after_rebuild(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);

        $order = $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 150000]);
        $order->delete();
        $order->restore();

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(1, $profile->total_orders);
        $this->assertEquals(150000.0, $profile->total_spending);
    }

    /** Dry-run computes values but writes nothing to database */
    public function test_dry_run_does_not_write_to_database(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant); // profile auto-created (zeros)

        // Insert directly to bypass DeliveryOrderObserver (which would update the profile)
        DB::table('delivery_orders')->insert([
            'ulid'             => Str::ulid(),
            'order_number'     => 'ORD-DRY-' . rand(10000, 99999),
            'merchant_id'      => $customer->merchant_id,
            'customer_id'      => $customer->id,
            'customer_name'    => $customer->customer_name,
            'product_name'     => 'Test Product',
            'delivery_address' => 'Jl. Test No. 1',
            'status'           => 'delivered',
            'order_value'      => 500000,
            'order_created_at' => now()->subDays(5)->toDateTimeString(),
            'delivered_at'     => now()->subDays(5)->addHours(2)->toDateTimeString(),
            'created_at'       => now()->toDateTimeString(),
            'updated_at'       => now()->toDateTimeString(),
        ]);

        // Profile still shows zeros (observer never fired)
        $profile = $this->getProfile($customer);
        $this->assertEquals(0, $profile->total_orders);

        $this->artisan('customers:rebuild', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN');

        // After dry-run, profile must still be unchanged
        $profile = $this->getProfile($customer);
        $this->assertEquals(0, $profile->total_orders);
        $this->assertEquals(0.0, $profile->total_spending);
    }

    /** --merchant restricts rebuild to the specified merchant */
    public function test_merchant_filter_only_processes_target_merchant(): void
    {
        $merchantA = $this->createMerchant();
        $merchantB = $this->createMerchant();
        $this->enableCustomerDomain($merchantA);
        $this->enableCustomerDomain($merchantB);

        $customerA = $this->createCustomer($merchantA);
        $customerB = $this->createCustomer($merchantB);
        $this->createOrder($customerA, ['order_value' => 100000]);
        $this->createOrder($customerB, ['order_value' => 200000]);

        // Artificially zero both profiles so we can detect which was rebuilt
        $this->forceProfile($customerA, ['total_orders' => 0]);
        $this->forceProfile($customerB, ['total_orders' => 0]);

        $this->artisan('customers:rebuild', ['--merchant' => $merchantA->id])
            ->assertSuccessful();

        $this->assertEquals(1, $this->getProfile($customerA)->total_orders);
        $this->assertEquals(0, $this->getProfile($customerB)->total_orders); // untouched
    }

    /** --customer rebuilds only that specific customer */
    public function test_customer_filter_only_processes_target_customer(): void
    {
        $merchant  = $this->createMerchant();
        $this->enableCustomerDomain($merchant);

        $customerA = $this->createCustomer($merchant);
        $customerB = $this->createCustomer($merchant);
        $this->createOrder($customerA, ['order_value' => 100000]);
        $this->createOrder($customerB, ['order_value' => 200000]);

        $this->forceProfile($customerA, ['total_orders' => 0]);
        $this->forceProfile($customerB, ['total_orders' => 0]);

        $this->artisan('customers:rebuild', ['--customer' => $customerA->id])
            ->assertSuccessful();

        $this->assertEquals(1, $this->getProfile($customerA)->total_orders);
        $this->assertEquals(0, $this->getProfile($customerB)->total_orders); // untouched
    }

    /** Running rebuild twice produces the same result (idempotent) */
    public function test_idempotent_multiple_runs_produce_same_result(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 100000]);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 200000]);

        $this->artisan('customers:rebuild')->assertSuccessful();
        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(2, $profile->total_orders);
        $this->assertEquals(300000.0, $profile->total_spending);
    }

    /** Merchants without customer_domain feature are skipped */
    public function test_skips_merchants_without_customer_domain_feature(): void
    {
        $merchant = $this->createMerchant();
        // customer_domain NOT enabled — profile will NOT be auto-created
        $customer = $this->createCustomer($merchant);

        // Manually create profile row to check it stays untouched
        $this->forceProfile($customer, ['total_orders' => 0, 'total_spending' => 0]);
        $this->createOrder($customer, ['order_value' => 100000]);

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);
        $this->assertEquals(0, $profile->total_orders); // untouched
    }

    /** --chunk option processes all customers regardless of batch size */
    public function test_chunk_option_processes_all_customers(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);

        for ($i = 0; $i < 5; $i++) {
            $customer = $this->createCustomer($merchant);
            $this->createOrder($customer, ['order_value' => 50000]);
        }

        $this->artisan('customers:rebuild', ['--merchant' => $merchant->id, '--chunk' => 2])
            ->assertSuccessful();

        $rebuilt = CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->where('merchant_id', $merchant->id)
            ->where('total_orders', 1)
            ->count();

        $this->assertEquals(5, $rebuilt);
    }

    /** Profile row missing — gets created during rebuild */
    public function test_creates_profile_if_missing(): void
    {
        $merchant = $this->createMerchant();
        // Enable feature AFTER customer creation so listener doesn't fire
        $customer = $this->createCustomer($merchant);
        $this->enableCustomerDomain($merchant);

        // Delete any auto-created profile to simulate missing row
        CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->delete();

        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 100000]);

        $this->artisan('customers:rebuild', ['--merchant' => $merchant->id])
            ->assertSuccessful();

        $profile = CustomerProfile::withoutGlobalScope(MerchantScope::class)
            ->where('customer_id', $customer->id)->first();

        $this->assertNotNull($profile);
        $this->assertEquals(1, $profile->total_orders);
    }

    /** Health status is recalculated based on order recency */
    public function test_health_status_recalculated_correctly(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);

        // Insert directly to bypass observer — profile stays at its initial zeros
        // so the rebuild command is the only thing that recalculates health
        DB::table('delivery_orders')->insert([
            'ulid'             => Str::ulid(),
            'order_number'     => 'ORD-HEALTH-' . rand(10000, 99999),
            'merchant_id'      => $customer->merchant_id,
            'customer_id'      => $customer->id,
            'customer_name'    => $customer->customer_name,
            'product_name'     => 'Test Product',
            'delivery_address' => 'Jl. Test No. 1',
            'status'           => 'delivered',
            'order_value'      => 100000,
            'order_created_at' => now()->subDays(200)->toDateTimeString(),
            'delivered_at'     => now()->subDays(200)->addHours(2)->toDateTimeString(),
            'created_at'       => now()->toDateTimeString(),
            'updated_at'       => now()->toDateTimeString(),
        ]);

        $this->artisan('customers:rebuild')->assertSuccessful();

        $profile = $this->getProfile($customer);

        // Customer with last order 200 days ago must be classified as 'lost'
        $this->assertEquals('lost', $profile->health_status);
    }

    /** Segment is recalculated to high_value when spending exceeds threshold */
    public function test_segment_recalculated_to_high_value(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);
        $this->forceProfile($customer, ['segment' => 'new']);

        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 3000000]);
        $this->createOrder($customer, ['status' => 'delivered', 'order_value' => 2500000]);

        $this->artisan('customers:rebuild')->assertSuccessful();

        $this->assertEquals('high_value', $this->getProfile($customer)->segment);
    }

    /** Summary output contains expected counter labels */
    public function test_output_contains_summary(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $this->createCustomer($merchant);

        $this->artisan('customers:rebuild')
            ->assertSuccessful()
            ->expectsOutputToContain('Customers Processed')
            ->expectsOutputToContain('Profiles Rebuilt')
            ->expectsOutputToContain('Timeline Events');
    }

    /** Timeline backfill creates entries for historical orders without duplicating existing ones */
    public function test_timeline_backfill_creates_historical_entries(): void
    {
        $merchant = $this->createMerchant();
        $this->enableCustomerDomain($merchant);
        $customer = $this->createCustomer($merchant);

        // Insert 3 historical orders directly (bypasses observer, so no timeline entries created).
        // All rows must have identical columns for SQLite bulk insert.
        $base = [
            'merchant_id'      => $customer->merchant_id,
            'customer_id'      => $customer->id,
            'customer_name'    => $customer->customer_name,
            'delivery_address' => 'Jl. A No.1',
            'product_name'     => 'Product',
            'order_value'      => 100000,
            'delivered_at'     => null,
            'failed_at'        => null,
            'failure_reason'   => null,
            'created_at'       => now()->toDateTimeString(),
            'updated_at'       => now()->toDateTimeString(),
        ];
        DB::table('delivery_orders')->insert([
            $base + [
                'ulid'             => Str::ulid(),
                'order_number'     => 'ORD-HIST-001',
                'status'           => 'delivered',
                'order_value'      => 100000,
                'order_created_at' => now()->subDays(30)->toDateTimeString(),
                'delivered_at'     => now()->subDays(30)->addHours(2)->toDateTimeString(),
            ],
            $base + [
                'ulid'             => Str::ulid(),
                'order_number'     => 'ORD-HIST-002',
                'status'           => 'failed',
                'order_value'      => 50000,
                'order_created_at' => now()->subDays(20)->toDateTimeString(),
                'failed_at'        => now()->subDays(20)->addHours(3)->toDateTimeString(),
                'failure_reason'   => 'Customer not home',
            ],
            $base + [
                'ulid'             => Str::ulid(),
                'order_number'     => 'ORD-HIST-003',
                'status'           => 'delivered',
                'order_value'      => 200000,
                'order_created_at' => now()->subDays(10)->toDateTimeString(),
                'delivered_at'     => now()->subDays(10)->addHours(1)->toDateTimeString(),
            ],
        ]);

        // Confirm no timeline entries exist before rebuild
        $this->assertEquals(0, \App\Models\CustomerTimeline::withoutGlobalScope(\App\Models\Scopes\MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->whereIn('event_type', ['order_created', 'order_delivered', 'order_failed'])
            ->count());

        $this->artisan('customers:rebuild')->assertSuccessful();

        $entries = \App\Models\CustomerTimeline::withoutGlobalScope(\App\Models\Scopes\MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->get(['event_type', 'event_data']);

        // 3 orders × order_created + 2 delivered × order_delivered + 1 failed × order_failed = 6 entries
        $this->assertEquals(3, $entries->where('event_type', 'order_created')->count());
        $this->assertEquals(2, $entries->where('event_type', 'order_delivered')->count());
        $this->assertEquals(1, $entries->where('event_type', 'order_failed')->count());

        // Running again must not duplicate anything
        $this->artisan('customers:rebuild')->assertSuccessful();
        $this->assertEquals(6, \App\Models\CustomerTimeline::withoutGlobalScope(\App\Models\Scopes\MerchantScope::class)
            ->where('customer_id', $customer->id)
            ->whereIn('event_type', ['order_created', 'order_delivered', 'order_failed'])
            ->count());
    }
}
