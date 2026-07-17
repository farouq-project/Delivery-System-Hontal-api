<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeletePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function merchant_owner_cannot_permanently_delete_users(): void
    {
        $merchant = Merchant::factory()->create();
        $owner    = User::factory()->create(['role' => 'merchant_owner', 'merchant_id' => $merchant->id]);
        $target   = User::factory()->create(['role' => 'dispatcher',     'merchant_id' => $merchant->id]);

        $this->actingAs($owner)->deleteJson("/api/v1/users/{$target->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Merchant owners cannot permanently delete users.');
    }

    /** @test */
    public function super_admin_can_permanently_delete_users(): void
    {
        $admin  = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$target->id}")
            ->assertNoContent();

        $this->assertNull(User::find($target->id));
    }

    /** @test */
    public function developer_can_permanently_delete_users(): void
    {
        $dev    = User::factory()->create(['role' => 'developer']);
        $target = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dev)->deleteJson("/api/v1/users/{$target->id}")
            ->assertNoContent();
    }

    /** @test */
    public function merchant_owner_cannot_delete_cross_merchant_users(): void
    {
        $merchantA = Merchant::factory()->create();
        $merchantB = Merchant::factory()->create();
        $owner     = User::factory()->create(['role' => 'merchant_owner', 'merchant_id' => $merchantA->id]);
        $target    = User::factory()->create(['role' => 'dispatcher',     'merchant_id' => $merchantB->id]);

        // authorizeManage() blocks cross-merchant first, so this still 403s
        $this->actingAs($owner)->deleteJson("/api/v1/users/{$target->id}")
            ->assertForbidden();
    }
}
