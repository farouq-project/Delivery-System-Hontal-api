<?php

namespace Tests\Feature;

use App\Models\GoogleApiUsageLog;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleApiAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'merchant_id' => null]);
    }

    private function makeLog(int $merchantId, bool $cacheHit = false, int $units = 10): void
    {
        GoogleApiUsageLog::create([
            'merchant_id'     => $merchantId,
            'api_type'        => 'geocoding',
            'request_count'   => 1,
            'estimated_units' => $units,
            'cache_hit'       => $cacheHit,
            'cache_key'       => md5(rand()),
            'response_time_ms'=> 150,
        ]);
    }

    public function test_analytics_returns_correct_structure(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/analytics/google-api');

        $res->assertOk()
            ->assertJsonStructure(['data' => [
                'today'         => ['requests', 'estimated_units', 'cache_hits', 'cache_hit_rate'],
                'this_month'    => ['requests', 'estimated_units', 'cache_hits', 'cache_hit_rate'],
                'top_consumers' => [],
                'daily_trend'   => [],
            ]]);
    }

    public function test_analytics_counts_todays_requests(): void
    {
        $merchant = Merchant::factory()->create();
        $this->makeLog($merchant->id);
        $this->makeLog($merchant->id);
        $this->makeLog($merchant->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/analytics/google-api');

        $res->assertOk();
        $this->assertGreaterThanOrEqual(3, $res->json('data.today.requests'));
    }

    public function test_analytics_cache_hit_rate_is_correct(): void
    {
        $merchant = Merchant::factory()->create();
        // 2 hits, 2 misses → 50%
        $this->makeLog($merchant->id, cacheHit: true);
        $this->makeLog($merchant->id, cacheHit: true);
        $this->makeLog($merchant->id, cacheHit: false);
        $this->makeLog($merchant->id, cacheHit: false);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/analytics/google-api');

        $res->assertOk();
        $this->assertEquals(50.0, $res->json('data.today.cache_hit_rate'));
    }

    public function test_top_consumers_ordered_by_request_count(): void
    {
        $m1 = Merchant::factory()->create();
        $m2 = Merchant::factory()->create();

        for ($i = 0; $i < 10; $i++) {
            $this->makeLog($m1->id);
        }
        $this->makeLog($m2->id);
        $this->makeLog($m2->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/analytics/google-api');

        $consumers = $res->json('data.top_consumers');
        $this->assertNotEmpty($consumers);
        $this->assertEquals($m1->id, $consumers[0]['merchant_id']);
    }

    public function test_analytics_rejected_for_non_admin(): void
    {
        $merchant = Merchant::factory()->create();
        $user = User::factory()->create(['role' => 'merchant_owner', 'merchant_id' => $merchant->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/analytics/google-api')
            ->assertForbidden();
    }
}
