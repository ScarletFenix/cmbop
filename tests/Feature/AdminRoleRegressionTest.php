<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteClaim;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin-role regressions found by a full panel pass: validation 500s,
 * missing-row 500s, and community claims that 500 when the listing is gone.
 */
class AdminRoleRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_company_update_validation_is_422_not_500(): void
    {
        $admin = $this->userWithRole('admin');
        $user = $this->userWithRole('advertiser', ['company_name' => 'Old Co']);

        $this->actingAs($admin)
            ->postJson(route('admin.users.updateCompany', $user->id), [
                'company_name' => str_repeat('X', 256),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);

        $this->assertSame('Old Co', $user->fresh()->company_name);
    }

    public function test_payment_status_update_for_missing_order_is_404_not_500(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', 999999), [
                'payment_status' => 'paid',
            ])
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Payment not found');
    }

    public function test_community_claims_tab_survives_a_missing_listing(): void
    {
        $admin = $this->userWithRole('admin');
        $claimer = $this->userWithRole('advertiser', [
            'name' => 'Orphan Claimer',
            'email' => 'orphan-claimer@example.com',
        ]);

        Schema::disableForeignKeyConstraints();
        try {
            SiteClaim::create([
                'site_id' => 999999,
                'claimer_id' => $claimer->id,
                'website_name' => 'Ghost listing',
                'website_url' => 'https://ghost.example',
                'domain' => 'ghost.example',
                'proof_message' => 'I still own this.',
                'contact_email' => $claimer->email,
                'status' => 'pending',
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->actingAs($admin)
            ->get(route('admin.community.index', ['tab' => 'claims']))
            ->assertOk()
            ->assertSee('Ghost listing')
            ->assertSee('Orphan Claimer');
    }
}
