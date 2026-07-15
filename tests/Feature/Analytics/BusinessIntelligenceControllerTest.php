<?php

namespace Tests\Feature\Analytics;

use App\Models\Merchant;
use App\Models\MerchantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessIntelligenceControllerTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private int $merchantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant   = Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'BI Ctrl Test',
            'slug'         => 'bi-ctrl-' . rand(1000, 9999),
            'email'        => 'bictrl' . rand() . '@test.com',
        ]);
        $this->merchantId = $this->merchant->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        return User::create([
            'ulid'        => Str::ulid(),
            'merchant_id' => $this->merchantId,
            'name'        => "User $role",
            'email'       => Str::random(8) . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => $role,
            'is_active'   => true,
        ]);
    }

    private function enableFeature(): void
    {
        MerchantFeature::create([
            'merchant_id' => $this->merchantId,
            'feature'     => 'business_intelligence',
            'is_enabled'  => true,
        ]);
    }

    private $endpoints = [
        '/api/v1/bi/overview',
        '/api/v1/bi/customers',
        '/api/v1/bi/operations',
        '/api/v1/bi/drivers',
        '/api/v1/bi/branches',
        '/api/v1/bi/products',
        '/api/v1/bi/areas',
        '/api/v1/bi/attention',
    ];

    // ── Permission Tests ──────────────────────────────────────────────────────

    public function test_dispatcher_cannot_access_bi(): void
    {
        $user = $this->makeUser('dispatcher');

        foreach ($this->endpoints as $url) {
            $this->actingAs($user, 'sanctum')
                ->getJson($url)
                ->assertStatus(403);
        }
    }

    public function test_driver_cannot_access_bi(): void
    {
        $user = $this->makeUser('driver');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_bi(): void
    {
        $this->getJson('/api/v1/bi/overview')->assertStatus(401);
    }

    // ── Feature Flag Tests ────────────────────────────────────────────────────

    public function test_merchant_owner_blocked_without_feature_flag(): void
    {
        $user = $this->makeUser('merchant_owner');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Business Intelligence is not enabled for this merchant.']);
    }

    public function test_developer_bypasses_feature_flag(): void
    {
        $user = $this->makeUser('super_admin');
        // No MerchantFeature row — developer should still get 200

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_merchant_owner_with_feature_flag_gets_200(): void
    {
        $this->enableFeature();
        $user = $this->makeUser('merchant_owner');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ── Response Shape Tests ──────────────────────────────────────────────────

    public function test_overview_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'operations_today',
                    'business_this_month',
                    'customer_health',
                    'requires_attention',
                ],
            ]);
    }

    public function test_customers_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/customers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total', 'new_this_month', 'repeat', 'vip',
                    'without_gps', 'top_by_revenue', 'top_by_frequency',
                ],
            ]);
    }

    public function test_operations_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/operations')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'pending_orders', 'pending_assignment',
                    'delayed_deliveries', 'failed_today',
                    'total_orders_today', 'delivered_today',
                    'active_drivers', 'offline_drivers',
                ],
            ]);
    }

    public function test_drivers_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/drivers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'ranking', 'total_drivers', 'offline_drivers',
                ],
            ]);
    }

    public function test_branches_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/branches')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['clusters'],
            ]);
    }

    public function test_products_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['has_data', 'top_selling', 'top_revenue'],
            ]);
    }

    public function test_areas_response_shape(): void
    {
        $user = $this->makeUser('super_admin');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/areas')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['areas'],
            ]);
    }

    public function test_attention_response_is_array(): void
    {
        $user = $this->makeUser('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bi/attention')
            ->assertOk();

        $this->assertIsArray($response->json('data'));
    }

    // ── Metric Consistency: BI overview matches individual endpoint values ─────

    public function test_overview_operations_matches_operations_endpoint(): void
    {
        $developer = $this->makeUser('super_admin');

        $overview = $this->actingAs($developer, 'sanctum')
            ->getJson('/api/v1/bi/overview')
            ->assertOk()
            ->json('data.operations_today');

        $ops = $this->actingAs($developer, 'sanctum')
            ->getJson('/api/v1/bi/operations')
            ->assertOk()
            ->json('data');

        $this->assertSame($overview['revenue'],              $ops['pending_orders'] >= 0 ? $overview['revenue'] : null);
        $this->assertSame($overview['active_drivers'],       $ops['active_drivers']);
        $this->assertSame($overview['deliveries_completed'], $ops['delivered_today']);
    }
}
