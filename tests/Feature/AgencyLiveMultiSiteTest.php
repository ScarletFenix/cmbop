<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Legacy live multi-site table was replaced by the guided bulk workflow.
 * See BulkSiteGuidedWorkflowTest for the new path.
 */
class AgencyLiveMultiSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);
    }

    public function test_websites_page_no_longer_exposes_self_serve_bulk_table(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));
        $css = file_get_contents(public_path('assets/css/publisher-websites.css'));

        $this->assertStringNotContainsString('publisher.sites.bulk-store', $blade.$js);
        $this->assertStringNotContainsString('.live-bulk-table', $css);
        $this->assertStringContainsString('publisher.bulk-sites.request', $blade);
        $this->assertStringContainsString('I want to add many sites', $blade);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Add New Website', false)
            ->assertSee('I want to add many sites', false)
            ->assertDontSee('liveBulkForm', false)
            ->assertDontSee('Fill every column for each site in one table', false);
    }

    public function test_websites_page_script_is_valid_javascript(): void
    {
        $js = file_get_contents(public_path('assets/js/publisher-websites.js'));

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*for CREATE/m',
            $js,
            'publisher-websites.js must not contain an un-commented "for CREATE" line'
        );
        $this->assertStringContainsString('// Toggle form for CREATE', $js);
        $this->assertStringContainsString("$('#addSiteForm').submit", $js);
        $this->assertStringContainsString("$('#showFormBtn')", $js);
        $this->assertStringNotContainsString('bulkCard', $js);
        $this->assertStringNotContainsString('{{', $js);
    }
}
