<?php

namespace Tests\Feature;

use App\Models\CrmProspect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmProspectTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'merchant_id' => null]);
    }

    public function test_list_prospects_returns_200(): void
    {
        CrmProspect::create(['business_name' => 'UD Tirta', 'pipeline_stage' => 'new']);

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/admin/crm')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_create_prospect_returns_201(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/crm', [
                'business_name'  => 'Bakti Gallon',
                'category'       => 'water',
                'city'           => 'Bandung',
                'pipeline_stage' => 'contacted',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.business_name', 'Bakti Gallon')
            ->assertJsonPath('data.pipeline_stage', 'contacted');
    }

    public function test_create_prospect_validates_stage(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/admin/crm', [
                'business_name'  => 'X',
                'pipeline_stage' => 'invalid_stage',
            ])
            ->assertStatus(422);
    }

    public function test_update_prospect_changes_stage(): void
    {
        $prospect = CrmProspect::create(['business_name' => 'Maju Jaya', 'pipeline_stage' => 'new']);

        $this->actingAs($this->superAdmin())
            ->patchJson("/api/v1/admin/crm/{$prospect->id}", [
                'pipeline_stage' => 'won',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.pipeline_stage', 'won');
    }

    public function test_delete_prospect_returns_204(): void
    {
        $prospect = CrmProspect::create(['business_name' => 'Gone Corp', 'pipeline_stage' => 'lost']);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/v1/admin/crm/{$prospect->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('crm_prospects', ['id' => $prospect->id]);
    }

    public function test_stats_returns_expected_structure(): void
    {
        CrmProspect::create(['business_name' => 'A', 'pipeline_stage' => 'new']);
        CrmProspect::create(['business_name' => 'B', 'pipeline_stage' => 'won']);

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/admin/crm/stats')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['by_stage', 'due_today', 'overdue', 'total']]);
    }

    public function test_filter_by_stage(): void
    {
        CrmProspect::create(['business_name' => 'New Co', 'pipeline_stage' => 'new']);
        CrmProspect::create(['business_name' => 'Won Co', 'pipeline_stage' => 'won']);

        $res = $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/admin/crm?stage=new')
            ->assertStatus(200);

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals('New Co', $res->json('data.0.business_name'));
    }

    public function test_non_admin_cannot_access_crm(): void
    {
        $owner = User::factory()->create(['role' => 'merchant_owner']);

        $this->actingAs($owner)
            ->getJson('/api/v1/admin/crm')
            ->assertStatus(403);
    }
}
