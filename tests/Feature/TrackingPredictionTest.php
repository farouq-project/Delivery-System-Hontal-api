<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DeliveryOrder;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingPredictionTest extends TestCase
{
    use RefreshDatabase;

    private function makeMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create(['timezone' => 'Asia/Jakarta']);
        MerchantSetting::factory()->create([
            'merchant_id'             => $merchant->id,
            'public_tracking_enabled' => true,
            'tracking_expiry_hours'   => 48,
        ]);
        return $merchant;
    }

    private function makeOrder(Merchant $merchant, ?Customer $customer = null): DeliveryOrder
    {
        return DeliveryOrder::factory()->create([
            'merchant_id'      => $merchant->id,
            'customer_id'      => $customer?->id,
            'status'           => 'pending',
            'order_created_at' => now()->subHour(),
        ]);
    }

    private function makeCustomerProfile(Customer $customer, Merchant $merchant, float $avgHours): void
    {
        CustomerProfile::create([
            'customer_id'            => $customer->id,
            'merchant_id'            => $merchant->id,
            'avg_delivery_time_hours'=> $avgHours,
        ]);
    }

    public function test_prediction_uses_customer_history_when_available(): void
    {
        $merchant = $this->makeMerchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $this->makeCustomerProfile($customer, $merchant, 2.0);
        $order = $this->makeOrder($merchant, $customer);

        $res = $this->getJson("/api/v1/track/{$order->ulid}");

        $res->assertOk();
        $this->assertEquals('customer_history', $res->json('data.prediction_source'));
        $this->assertNotNull($res->json('data.predicted_delivery_time'));
    }

    public function test_prediction_falls_back_to_merchant_average(): void
    {
        $merchant = $this->makeMerchant();
        // No customer profile — seed merchant-level delivered history
        $base = now()->subDays(5);
        for ($i = 0; $i < 3; $i++) {
            DeliveryOrder::factory()->create([
                'merchant_id'      => $merchant->id,
                'status'           => 'delivered',
                'order_created_at' => (clone $base)->subHours(3),
                'delivered_at'     => $base,
                'created_at'       => $base,
            ]);
        }
        $order = $this->makeOrder($merchant);

        $res = $this->getJson("/api/v1/track/{$order->ulid}");

        $res->assertOk();
        $this->assertEquals('merchant_average', $res->json('data.prediction_source'));
        $this->assertNotNull($res->json('data.predicted_delivery_time'));
    }

    public function test_no_prediction_when_no_history(): void
    {
        $merchant = $this->makeMerchant();
        $order = $this->makeOrder($merchant);

        $res = $this->getJson("/api/v1/track/{$order->ulid}");

        $res->assertOk();
        $this->assertNull($res->json('data.predicted_delivery_time'));
        $this->assertNull($res->json('data.prediction_source'));
    }

    public function test_no_prediction_for_delivered_orders(): void
    {
        $merchant = $this->makeMerchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $this->makeCustomerProfile($customer, $merchant, 2.0);
        $order = DeliveryOrder::factory()->create([
            'merchant_id'      => $merchant->id,
            'customer_id'      => $customer->id,
            'status'           => 'delivered',
            'order_created_at' => now()->subHours(3),
            'delivered_at'     => now()->subHour(),
        ]);

        $res = $this->getJson("/api/v1/track/{$order->ulid}");

        $res->assertOk();
        $this->assertNull($res->json('data.predicted_delivery_time'));
    }

    public function test_customer_profile_takes_priority_over_merchant_average(): void
    {
        $merchant = $this->makeMerchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $this->makeCustomerProfile($customer, $merchant, 1.0);

        // Merchant history with 5-hour average
        $base = now()->subDays(3);
        for ($i = 0; $i < 5; $i++) {
            DeliveryOrder::factory()->create([
                'merchant_id'      => $merchant->id,
                'status'           => 'delivered',
                'order_created_at' => (clone $base)->subHours(5),
                'delivered_at'     => $base,
                'created_at'       => $base,
            ]);
        }
        $order = $this->makeOrder($merchant, $customer);

        $res = $this->getJson("/api/v1/track/{$order->ulid}");

        $res->assertOk();
        $this->assertEquals('customer_history', $res->json('data.prediction_source'));
    }
}
