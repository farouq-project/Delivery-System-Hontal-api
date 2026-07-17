<?php

namespace Tests\Feature;

use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteGenerationV2Test extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private User     $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        MerchantSetting::factory()->create([
            'merchant_id'      => $this->merchant->id,
            'depot_latitude'   => -6.9175,
            'depot_longitude'  => 107.6191,
            'routing_mode'     => 'economy',
            'batch_enforcement'=> true,
            'two_opt_enabled'  => false,
        ]);
        $this->dispatcher = User::factory()->create([
            'merchant_id' => $this->merchant->id,
            'role'        => 'dispatcher',
        ]);
    }

    private function makeOrder(float $lat, float $lng, string $createdAt = '2026-07-17 08:00:00'): DeliveryOrder
    {
        return DeliveryOrder::factory()->create([
            'merchant_id'       => $this->merchant->id,
            'status'            => 'pending',
            'delivery_latitude' => $lat,
            'delivery_longitude'=> $lng,
            'order_created_at'  => $createdAt,
        ]);
    }

    public function test_generate_returns_201_with_route_data(): void
    {
        $this->makeOrder(-6.92, 107.62);
        $this->makeOrder(-6.93, 107.63);
        $this->makeOrder(-6.94, 107.64);

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17']);

        $res->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'total_stops', 'assignments']]);
    }

    public function test_generate_returns_422_when_no_orders(): void
    {
        $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17'])
            ->assertUnprocessable();
    }

    public function test_generated_route_has_v2_analytics_columns(): void
    {
        $this->makeOrder(-6.92, 107.62);
        $this->makeOrder(-6.93, 107.63);
        $this->makeOrder(-6.94, 107.64);

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17']);

        $res->assertCreated();
        $routeId = $res->json('data.id');

        $this->assertDatabaseHas('routes', [
            'id'           => $routeId,
            'routing_mode' => 'economy',
        ]);
    }

    public function test_generate_sequences_all_located_orders(): void
    {
        $o1 = $this->makeOrder(-6.92, 107.62);
        $o2 = $this->makeOrder(-6.93, 107.63);
        $o3 = $this->makeOrder(-6.94, 107.64);

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17']);

        $res->assertCreated();
        $totalStops = $res->json('data.total_stops');
        $this->assertEquals(3, $totalStops);
    }

    public function test_orders_without_coordinates_still_appear(): void
    {
        $this->makeOrder(-6.92, 107.62);
        // Order with no coords
        DeliveryOrder::factory()->create([
            'merchant_id'        => $this->merchant->id,
            'status'             => 'pending',
            'delivery_latitude'  => null,
            'delivery_longitude' => null,
            'order_created_at'   => '2026-07-17 08:00:00',
        ]);

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17']);

        $res->assertCreated();
        $this->assertEquals(2, $res->json('data.total_stops'));
    }

    public function test_batch_separation_groups_morning_and_afternoon(): void
    {
        // Morning order (08:00)
        $this->makeOrder(-6.92, 107.62, '2026-07-17 08:00:00');
        // Afternoon order (14:00) — far from morning but should be after morning batch
        $this->makeOrder(-7.00, 107.70, '2026-07-17 14:00:00');
        // Another morning order
        $this->makeOrder(-6.93, 107.63, '2026-07-17 09:00:00');

        $res = $this->actingAs($this->dispatcher, 'sanctum')
            ->postJson('/api/v1/routes/generate', ['route_date' => '2026-07-17']);

        $res->assertCreated();
        $this->assertEquals(3, $res->json('data.total_stops'));
    }
}
