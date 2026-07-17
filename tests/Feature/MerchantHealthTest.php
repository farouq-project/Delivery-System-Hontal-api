<?php

namespace Tests\Feature;

use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'merchant_id' => null]);
    }

    private function makeActiveMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create();
        MerchantSubscription::factory()->create([
            'merchant_id' => $merchant->id,
            'status'      => 'active',
        ]);
        return $merchant;
    }

    public function test_healthy_merchant_classified_correctly(): void
    {
        $merchant = $this->makeActiveMerchant();
        // Recent order (today)
        DeliveryOrder::factory()->create([
            'merchant_id'   => $merchant->id,
            'status'        => 'delivered',
            'created_at'    => now(),
        ]);
        // High success rate: 4 delivered, 1 failed
        DeliveryOrder::factory()->count(3)->create([
            'merchant_id' => $merchant->id,
            'status'      => 'delivered',
            'created_at'  => now()->subDays(3),
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk();
        $record = collect($res->json('data'))->firstWhere('id', $merchant->id);
        $this->assertNotNull($record);
        $this->assertEquals('healthy', $record['health']);
    }

    public function test_inactive_merchant_classified_by_no_subscription(): void
    {
        // Merchant with no subscription
        $merchant = Merchant::factory()->create();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk();
        $record = collect($res->json('data'))->firstWhere('id', $merchant->id);
        $this->assertNotNull($record);
        $this->assertEquals('inactive', $record['health']);
    }

    public function test_inactive_merchant_classified_by_expired_subscription(): void
    {
        $merchant = Merchant::factory()->create();
        MerchantSubscription::factory()->create([
            'merchant_id' => $merchant->id,
            'status'      => 'expired',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk();
        $record = collect($res->json('data'))->firstWhere('id', $merchant->id);
        $this->assertEquals('inactive', $record['health']);
    }

    public function test_needs_attention_merchant_classified_by_stale_orders(): void
    {
        $merchant = $this->makeActiveMerchant();
        // Last order was 15 days ago (stale but not inactive)
        DeliveryOrder::factory()->create([
            'merchant_id' => $merchant->id,
            'status'      => 'delivered',
            'created_at'  => now()->subDays(15),
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk();
        $record = collect($res->json('data'))->firstWhere('id', $merchant->id);
        $this->assertNotNull($record);
        $this->assertEquals('needs_attention', $record['health']);
    }

    public function test_health_response_includes_required_fields(): void
    {
        $merchant = $this->makeActiveMerchant();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'company_name', 'subscription_status',
                'last_login_at', 'last_order_at', 'monthly_orders',
                'active_drivers', 'active_dispatchers',
                'delivery_success_rate', 'customer_count', 'health',
            ]]]);
    }
}
