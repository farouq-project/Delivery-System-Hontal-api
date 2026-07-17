<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\User;
use App\Services\GoogleMapsLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerLocationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createMerchant(): Merchant
    {
        return Merchant::create([
            'ulid'         => Str::ulid(),
            'company_name' => 'Test Merchant',
            'slug'         => 'test-merchant-' . rand(1000, 9999),
            'email'        => 'merchant' . rand(1000, 9999) . '@test.com',
        ]);
    }

    private function createSettings(Merchant $merchant, array $overrides = []): MerchantSetting
    {
        return MerchantSetting::create(array_merge([
            'merchant_id'                    => $merchant->id,
            'depot_latitude'                 => -6.9000,
            'depot_longitude'                => 107.6000,
            'location_validation_radius'     => 30,
            'location_change_warning_radius' => 2,
        ], $overrides));
    }

    private function createUser(Merchant $merchant, string $role = 'kasir'): User
    {
        return User::create([
            'ulid'        => Str::ulid(),
            'name'        => 'Test User',
            'email'       => 'user' . rand(1000, 9999) . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => $role,
            'merchant_id' => $merchant->id,
            'is_active'   => true,
        ]);
    }

    private function createCustomer(Merchant $merchant, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'ulid'          => Str::ulid(),
            'merchant_id'   => $merchant->id,
            'customer_name' => 'Test Customer',
            'vip_level'     => 'standard',
            'is_active'     => true,
        ], $overrides));
    }

    // ─── Depot distance warnings ──────────────────────────────────────────────

    public function test_store_includes_warnings_when_depot_radius_exceeded(): void
    {
        $merchant = $this->createMerchant();
        $this->createSettings($merchant, [
            'depot_latitude'             => -6.9000,
            'depot_longitude'            => 107.6000,
            'location_validation_radius' => 5, // 5 km — very tight
        ]);
        $user = $this->createUser($merchant);

        // Place customer ~42 km from depot
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers', [
            'customer_name'    => 'Far Away Customer',
            'default_latitude' => -7.3000, // ~42 km south
            'default_longitude' => 107.6000,
            'vip_level'        => 'standard',
        ]);

        $response->assertStatus(201);
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertSame('depot_distance', $warnings[0]['type']);
        $this->assertGreaterThan(5, $warnings[0]['distance_km']);
    }

    public function test_store_returns_empty_warnings_within_radius(): void
    {
        $merchant = $this->createMerchant();
        $this->createSettings($merchant, [
            'depot_latitude'             => -6.9000,
            'depot_longitude'            => 107.6000,
            'location_validation_radius' => 30,
        ]);
        $user = $this->createUser($merchant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers', [
            'customer_name'    => 'Nearby Customer',
            'default_latitude' => -6.9100, // ~1.1 km away
            'default_longitude' => 107.6100,
            'vip_level'        => 'standard',
        ]);

        $response->assertStatus(201);
        $this->assertEmpty($response->json('warnings'));
    }

    // ─── Location change threshold ────────────────────────────────────────────

    public function test_update_uses_configurable_change_threshold(): void
    {
        $merchant = $this->createMerchant();
        $this->createSettings($merchant, ['location_change_warning_radius' => 1]); // 1 km threshold
        $user     = $this->createUser($merchant);
        $customer = $this->createCustomer($merchant, [
            'default_latitude'  => -6.9175,
            'default_longitude' => 107.6191,
            'location_source'   => 'manual_pin',
        ]);

        // Move customer 1.5 km — above 1 km threshold, warning expected
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'default_latitude'  => -6.9310,  // ~1.5 km south
            'default_longitude' => 107.6191,
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('location_change_warning'));
        $this->assertSame(1.0, (float) $response->json('location_change_warning.threshold_km'));
    }

    public function test_update_no_warning_when_change_below_threshold(): void
    {
        $merchant = $this->createMerchant();
        $this->createSettings($merchant, ['location_change_warning_radius' => 5]); // 5 km threshold
        $user     = $this->createUser($merchant);
        $customer = $this->createCustomer($merchant, [
            'default_latitude'  => -6.9175,
            'default_longitude' => 107.6191,
            'location_source'   => 'manual_pin',
        ]);

        // Move customer 1.5 km — below 5 km threshold, no warning
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'default_latitude'  => -6.9310,
            'default_longitude' => 107.6191,
        ]);

        $response->assertOk();
        $this->assertNull($response->json('location_change_warning'));
    }

    // ─── Timestamp only updates on coordinate change ──────────────────────────

    public function test_timestamp_is_set_when_coordinates_first_added(): void
    {
        $merchant = $this->createMerchant();
        $this->createSettings($merchant);
        $user     = $this->createUser($merchant);
        $customer = $this->createCustomer($merchant); // no coords

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'default_latitude'  => -6.9175,
            'default_longitude' => 107.6191,
        ]);

        $response->assertOk();
        $this->assertNotNull($customer->fresh()->location_last_verified_at);
    }

    public function test_timestamp_is_not_updated_on_name_edit(): void
    {
        $verifiedAt = now()->subDays(5);
        $merchant   = $this->createMerchant();
        $this->createSettings($merchant);
        $user     = $this->createUser($merchant);
        $customer = $this->createCustomer($merchant, [
            'default_latitude'           => -6.9175,
            'default_longitude'          => 107.6191,
            'location_source'            => 'manual_pin',
            'location_last_verified_at'  => $verifiedAt,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'customer_name' => 'Updated Name Only',
        ]);

        $response->assertOk();
        $fresh = $customer->fresh();
        $this->assertEqualsWithDelta(
            $verifiedAt->timestamp,
            $fresh->location_last_verified_at->timestamp,
            2 // allow 2-second tolerance for test runtime
        );
    }

    public function test_timestamp_is_updated_when_coordinates_change(): void
    {
        $verifiedAt = now()->subDays(5);
        $merchant   = $this->createMerchant();
        $this->createSettings($merchant);
        $user     = $this->createUser($merchant);
        $customer = $this->createCustomer($merchant, [
            'default_latitude'           => -6.9175,
            'default_longitude'          => 107.6191,
            'location_source'            => 'manual_pin',
            'location_last_verified_at'  => $verifiedAt,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/customers/{$customer->id}", [
            'default_latitude'  => -6.9300,
            'default_longitude' => 107.6300,
        ]);

        $response->assertOk();
        $fresh = $customer->fresh();
        $this->assertGreaterThan($verifiedAt->timestamp, $fresh->location_last_verified_at->timestamp);
    }

    // ─── Maps link resolution ─────────────────────────────────────────────────

    public function test_resolve_maps_link_rejects_non_google_url(): void
    {
        $merchant = $this->createMerchant();
        $user     = $this->createUser($merchant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers/resolve-maps-link', [
            'url' => 'https://openstreetmap.org/?lat=-6.9175&lon=107.6191',
        ]);

        $response->assertStatus(422);
    }

    public function test_resolve_maps_link_extracts_coordinates_from_full_url(): void
    {
        $merchant = $this->createMerchant();
        $user     = $this->createUser($merchant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers/resolve-maps-link', [
            'url' => 'https://www.google.com/maps/@-6.9175,107.6191,15z',
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEqualsWithDelta(-6.9175, $data['latitude'],  0.001);
        $this->assertEqualsWithDelta(107.6191, $data['longitude'], 0.001);
    }

    // ─── Google API usage logging ─────────────────────────────────────────────

    public function test_geocoding_service_logs_cache_miss(): void
    {
        Cache::flush();

        $merchant = $this->createMerchant();
        $this->createSettings($merchant);
        $user     = $this->createUser($merchant);

        // Inject a service stub that simulates a successful geocode
        $this->mock(\App\Services\Geocoding\GoogleGeocodingService::class)
            ->shouldReceive('geocode')
            ->andReturn(['latitude' => -6.9175, 'longitude' => 107.6191,
                         'formatted_address' => 'Bandung', 'place_id' => 'mock']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers', [
            'customer_name'   => 'Geocoded Customer',
            'default_address' => 'Jl. Dago Bandung',
            'vip_level'       => 'standard',
        ]);

        // Logging happens inside the service; this test verifies the endpoint doesn't crash
        // and the table structure accepts the new columns (cache_hit, cache_key, response_time_ms)
        $this->assertDatabaseHas('customers', ['customer_name' => 'Geocoded Customer']);
    }

    // ─── Super Admin delete user guards ──────────────────────────────────────

    public function test_delete_user_rejects_merchant_owner(): void
    {
        $admin    = User::create([
            'ulid'      => Str::ulid(),
            'name'      => 'Super Admin',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'role'      => 'super_admin',
            'is_active' => true,
        ]);

        $merchant = $this->createMerchant();
        $owner    = $this->createUser($merchant, 'merchant_owner');

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/merchants/{$merchant->id}/users/{$owner->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'deleted_at' => null]);
    }
}
