<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialMerchantTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'merchant_id' => null]);
    }

    public function test_create_trial_merchant_returns_201(): void
    {
        $res = $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/trial-merchants', [
                'company_name' => 'Uji Coba Gallon',
                'owner_name'   => 'Pak Test',
                'owner_email'  => 'owner@test.demo',
                'password'     => 'demo1234',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.merchant.company_name', 'Uji Coba Gallon')
            ->assertJsonPath('data.owner_email', 'owner@test.demo');
    }

    public function test_create_trial_merchant_seeds_samples(): void
    {
        $res = $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/trial-merchants', [
                'company_name' => 'Sample Test Co',
                'owner_name'   => 'Pak Sample',
                'owner_email'  => 'owner@sample.demo',
                'password'     => 'demo1234',
                'with_samples' => true,
            ]);

        $res->assertStatus(201);
        $this->assertGreaterThan(0, $res->json('data.sample_customers'));
        $this->assertGreaterThan(0, $res->json('data.sample_orders'));
    }

    public function test_create_trial_merchant_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@existing.com', 'merchant_id' => null]);

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/trial-merchants', [
                'company_name' => 'Another Co',
                'owner_name'   => 'Test',
                'owner_email'  => 'dup@existing.com',
                'password'     => 'demo1234',
            ])
            ->assertStatus(422);
    }

    public function test_delete_trial_merchant_succeeds(): void
    {
        $create = $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/trial-merchants', [
                'company_name' => 'To Delete',
                'owner_name'   => 'Del Owner',
                'owner_email'  => 'del@toremove.demo',
                'password'     => 'demo1234',
            ]);
        $create->assertStatus(201);

        $merchantId = $create->json('data.merchant.id');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/admin/trial-merchants/{$merchantId}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('merchants', ['id' => $merchantId]);
    }

    public function test_non_admin_cannot_create_trial_merchant(): void
    {
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dispatcher)
            ->postJson('/api/v1/admin/trial-merchants', [
                'company_name' => 'Hack',
                'owner_name'   => 'Hack',
                'owner_email'  => 'hack@test.demo',
                'password'     => 'demo1234',
            ])
            ->assertStatus(403);
    }
}
