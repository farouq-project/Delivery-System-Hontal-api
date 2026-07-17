<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantActivityLog;
use App\Models\MerchantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'merchant_id' => null]);
    }

    public function test_dashboard_returns_stats(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard');

        $res->assertOk()
            ->assertJsonStructure(['data' => [
                'merchants' => ['total', 'active', 'trial', 'paid', 'suspended', 'expired', 'cancelled'],
                'active_users',
                'orders_today',
                'deliveries_today',
                'orders_this_month',
                'google_api' => ['requests_this_month', 'estimated_units_this_month', 'cache_hit_rate'],
                'platform_health',
            ]]);
    }

    public function test_dashboard_rejected_for_non_admin(): void
    {
        $merchant = Merchant::factory()->create();
        $user = User::factory()->create(['role' => 'merchant_owner', 'merchant_id' => $merchant->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_rejected_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_health_returns_merchant_list(): void
    {
        $merchant = Merchant::factory()->create();
        MerchantSubscription::factory()->create([
            'merchant_id' => $merchant->id,
            'status'      => 'active',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/health');

        $res->assertOk()
            ->assertJsonStructure(['data' => [['id', 'company_name', 'health']]]);
    }

    public function test_activity_feed_is_paginated(): void
    {
        $merchant = Merchant::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            MerchantActivityLog::create([
                'merchant_id' => $merchant->id,
                'event_type'  => 'test_event',
                'description' => "Event $i",
            ]);
        }

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/activity');

        $res->assertOk()
            ->assertJsonStructure(['data', 'total', 'current_page']);
    }

    public function test_activity_feed_filterable_by_merchant(): void
    {
        $m1 = Merchant::factory()->create();
        $m2 = Merchant::factory()->create();
        MerchantActivityLog::create([
            'merchant_id' => $m1->id,
            'event_type'  => 'user_created',
            'description' => 'A user was created',
        ]);
        MerchantActivityLog::create([
            'merchant_id' => $m2->id,
            'event_type'  => 'route_generated',
            'description' => 'Route was generated',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/activity?merchant_id={$m1->id}");

        $res->assertOk();
        $items = $res->json('data');
        $this->assertCount(1, $items);
        $this->assertEquals($m1->id, $items[0]['merchant_id']);
    }

    public function test_global_search_requires_two_chars(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/search?q=a')
            ->assertUnprocessable();
    }

    public function test_global_search_returns_typed_results(): void
    {
        Merchant::factory()->create(['company_name' => 'Toko Maju Bersama']);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/search?q=Toko');

        $res->assertOk()
            ->assertJsonStructure(['data' => [['type', 'id', 'label', 'sub', 'url']]]);

        $types = collect($res->json('data'))->pluck('type')->unique()->values()->toArray();
        $this->assertContains('merchant', $types);
    }
}
