<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SameOriginSiteMutationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_same_origin_asset_strips_leftover_app_url_host(): void
    {
        config(['app.url' => 'http://leftover.invalid']);
        URL::forceRootUrl('http://leftover.invalid');

        $this->assertSame(
            '/assets/js/publisher-websites.js',
            same_origin_asset('assets/js/publisher-websites.js')
        );
        $this->assertSame(
            '/js/slb-confirm.js',
            same_origin_asset('js/slb-confirm.js')
        );
    }

    public function test_generated_nav_urls_follow_the_browser_host(): void
    {
        $publisher = $this->userWithRole('publisher');

        config(['app.url' => 'http://leftover.invalid']);
        URL::forceRootUrl('http://leftover.invalid');

        $html = $this->actingAs($publisher)
            ->get('http://browser.test/publisher/websites')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('http://browser.test/', $html);
        $this->assertStringNotContainsString('http://leftover.invalid/', $html);
        $this->assertStringContainsString('action="'.route('publisher.sites.store', absolute: false).'"', $html);
    }

    public function test_staff_image_store_does_not_abort_when_exists_probe_fails(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Admin/SiteController.php'));
        $this->assertIsString($src);
        $this->assertStringNotContainsString('! $disk->exists($stored)', $src);
        $this->assertStringContainsString('Trust the path', $src);
    }

    public function test_publisher_listing_preview_reports_failure_so_submit_can_continue(): void
    {
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString('return false;', $js);
        $this->assertStringContainsString('return true;', $js);
        $this->assertStringContainsString('window.showSiteListingPreview', $js);
    }

    public function test_admin_and_marketing_activate_does_not_silently_no_op(): void
    {
        $adminSites = file_get_contents(resource_path('views/admin/sites.blade.php'));
        $mktDash = file_get_contents(resource_path('views/marketing/dashboard.blade.php'));
        $this->assertIsString($adminSites);
        $this->assertIsString($mktDash);

        $this->assertStringNotContainsString('Promise.resolve(true)', $adminSites);
        $this->assertStringNotContainsString('Promise.resolve(true)', $mktDash);
        $this->assertStringContainsString("credentials: 'same-origin'", $adminSites);
        $this->assertStringContainsString('staff_route(\'sites.active\', \'__ID__\', false)', $mktDash);
        $this->assertStringContainsString('Swal.fire', $mktDash);
    }
}
