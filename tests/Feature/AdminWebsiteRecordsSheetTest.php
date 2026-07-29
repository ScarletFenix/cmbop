<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWebsiteRecordsSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Records Sheet Site',
            'site_url' => 'https://records-sheet.example',
            'domain' => 'records-sheet.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'countries' => ['de', 'at'],
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology', 'Business & Finance'],
            'price' => 99,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Website records sheet test description.',
            'verified' => false,
            'active' => false,
        ]);
    }

    public function test_admin_can_view_websites_records_sheet(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher);

        $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('Websites records sheet', false)
            ->assertSee('Live from database', false)
            ->assertSee('https://records-sheet.example', false)
            ->assertSee('de|at', false)
            ->assertSee('Technology|Business &amp; Finance', false)
            ->assertDontSee('€99', false);
    }

    public function test_admin_can_download_websites_records_csv(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher);

        $response = $this->actingAs($admin)
            ->get(route('admin.sites.records.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('url,countries,categories', $csv);
        $this->assertStringContainsString('https://records-sheet.example', $csv);
        $this->assertStringContainsString('de|at', $csv);
        $this->assertStringContainsString('Technology|Business & Finance', $csv);
        $this->assertStringNotContainsString('Records Sheet Site', $csv);
    }

    public function test_non_admin_cannot_access_websites_records_sheet(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $advertiser = $this->userWithRoles(['advertiser'], 'advertiser');

        $this->actingAs($marketer)
            ->get(route('admin.sites.records'))
            ->assertRedirect();

        $this->actingAs($advertiser)
            ->get(route('admin.sites.records'))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->get(route('admin.sites.records.export'))
            ->assertForbidden();
    }

    public function test_admin_sites_index_links_to_records_sheet(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');

        $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('Websites records sheet', false)
            ->assertSee(route('admin.sites.records'), false);
    }
}
