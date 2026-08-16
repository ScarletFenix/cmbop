<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
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
        $publisher = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('advertiser', [
            'name' => 'Orphan Claimer',
            'email' => 'orphan-claimer@example.com',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ghost listing',
            'site_url' => 'https://ghost.example',
            'domain' => 'ghost.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 20,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Orphaned listing used to test claims',
            'verified' => true,
            'active' => true,
        ]);
        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $claimer->id,
            'website_name' => 'Ghost listing',
            'website_url' => 'https://ghost.example',
            'domain' => 'ghost.example',
            'proof_message' => 'I still own this.',
            'contact_email' => $claimer->email,
            'status' => 'pending',
        ]);
        $claim->setRelation('site', null);
        $claim->setRelation('claimer', $claimer);

        $this->actingAs($admin);
        $detail = view('admin.community.detail', [
            'tab' => 'claims',
            'item' => $claim,
            'ctx' => [
                'open_orders' => 0,
                'open_disputes' => 0,
                'verified' => false,
                'name_matches' => false,
                'claimer_has_publisher_role' => false,
            ],
            'siblings' => 0,
            'pageUrl' => null,
        ])->render();

        $this->assertStringContainsString('Ghost listing', $detail);
        $this->assertStringContainsString('Orphan Claimer', $detail);

        $rendered = Blade::render(
            '<a href="{{ route(\'admin.sites.edit\', $item->site_id) }}">{{ $item->site?->site_name ?? $item->website_name }}</a>'
            .'{{ $item->site?->publisher?->name ?? \'—\' }}',
            ['item' => $claim]
        );
        $this->assertStringContainsString('Ghost listing', $rendered);
        $this->assertStringContainsString('—', $rendered);
    }
}
