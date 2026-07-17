<?php

namespace Tests\Feature;

use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $orderAttrs = [], array $settingAttrs = []): DeliveryOrder
    {
        $merchant = Merchant::factory()->create();
        MerchantSetting::factory()->create(array_merge(
            ['merchant_id' => $merchant->id, 'public_tracking_enabled' => true],
            $settingAttrs
        ));

        return DeliveryOrder::factory()->create(array_merge(
            ['merchant_id' => $merchant->id, 'status' => 'in_transit'],
            $orderAttrs
        ));
    }

    /** @test */
    public function it_returns_tracking_data_for_valid_token(): void
    {
        $order = $this->makeOrder();

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['order_number', 'customer_name', 'status', 'merchant_name']]);
    }

    /** @test */
    public function it_maps_in_transit_status_to_in_progress(): void
    {
        $order = $this->makeOrder(['status' => 'in_transit']);

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    /** @test */
    public function it_passes_through_other_statuses_unchanged(): void
    {
        $order = $this->makeOrder(['status' => 'delivered']);

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    /** @test */
    public function it_returns_404_for_nonexistent_token(): void
    {
        $this->getJson('/api/v1/track/nonexistent-token-xyz')
            ->assertNotFound();
    }

    /** @test */
    public function it_returns_404_when_public_tracking_is_disabled(): void
    {
        $order = $this->makeOrder([], ['public_tracking_enabled' => false]);

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertNotFound();
    }

    /** @test */
    public function it_returns_404_for_expired_delivered_order(): void
    {
        $order = $this->makeOrder([
            'status'       => 'delivered',
            'delivered_at' => now()->subHours(49),
        ], ['tracking_expiry_hours' => 48]);

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertNotFound();
    }

    /** @test */
    public function it_returns_tracking_for_delivered_order_within_expiry(): void
    {
        $order = $this->makeOrder([
            'status'       => 'delivered',
            'delivered_at' => now()->subHours(10),
        ], ['tracking_expiry_hours' => 48]);

        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertOk();
    }

    /** @test */
    public function it_does_not_require_authentication(): void
    {
        $order = $this->makeOrder();

        // No auth header — should still return 200
        $this->getJson("/api/v1/track/{$order->ulid}")
            ->assertOk();
    }
}
