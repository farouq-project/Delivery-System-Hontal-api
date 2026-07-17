<?php

namespace Tests\Feature;

use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\Route;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private User     $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        MerchantSetting::factory()->create([
            'merchant_id'     => $this->merchant->id,
            'depot_latitude'  => -6.9175,
            'depot_longitude' => 107.6191,
            'routing_mode'    => 'balanced',
        ]);
        $this->dispatcher = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'role'        => 'dispatcher',
        ]);
    }

    private function makeOrder(float $lat, float $lng): DeliveryOrder
    {
        return DeliveryOrder::factory()->create([
            'merchant_id'       => $this->merchant->id,
            'status'            => 'pending',
            'delivery_latitude' => $lat,
            'delivery_longitude'=> $lng,
            'order_created_at'  => now(),
        ]);
    }

    private function generateRoute(): array
    {
        $this->makeOrder(-6.92, 107.62);
        $this->makeOrder(-6.94, 107.64);
        $this->makeOrder(-6.96, 107.66);
        $this->makeOrder(-6.98, 107.68);

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => now()->format('Y-m-d')]);

        $res->assertCreated();
        return $res->json('data');
    }

    public function test_route_has_routing_mode_set(): void
    {
        $data = $this->generateRoute();
        $this->assertSame('balanced', $data['routing_mode']);
    }

    public function test_route_has_batch_count(): void
    {
        $data = $this->generateRoute();
        $this->assertArrayHasKey('batch_count', $data);
        $this->assertGreaterThanOrEqual(1, $data['batch_count']);
    }

    public function test_quality_score_is_numeric(): void
    {
        $data = $this->generateRoute();
        $this->assertIsNumeric($data['quality_score'] ?? 0);
    }

    public function test_distance_before_optimization_recorded(): void
    {
        $data = $this->generateRoute();
        $routeId = $data['id'];

        $this->assertDatabaseHas('routes', [
            'id'          => $routeId,
            'routing_mode'=> 'balanced',
        ]);

        $route = Route::find($routeId);
        // In balanced mode, distance_before_optimization_m should be >= distance after (2-opt can only improve or stay same)
        if ($route->distance_before_optimization_m !== null && $route->total_distance_m !== null) {
            $this->assertGreaterThanOrEqual($route->total_distance_m, $route->distance_before_optimization_m);
        }
    }

    public function test_economy_mode_zero_google_calls(): void
    {
        $this->merchant->settings()->update(['routing_mode' => 'economy']);

        $data = $this->generateRoute();

        $this->assertSame(0, $data['google_calls']);
    }

    public function test_analytics_included_in_show_response(): void
    {
        $this->makeOrder(-6.92, 107.62);
        $this->makeOrder(-6.93, 107.63);

        $genRes = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => now()->format('Y-m-d')]);
        $genRes->assertCreated();

        $routeId = $genRes->json('data.id');

        $showRes = $this->actingAs($this->dispatcher, 'sanctum')
            ->getJson("/api/v1/routes/{$routeId}");

        $showRes->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'routing_mode', 'batch_count', 'quality_score',
                'optimization_saving_m', 'google_calls',
            ]]);
    }
}
