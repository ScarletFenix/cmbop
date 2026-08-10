<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bulk onboarding starts as a browser table the publisher grows by pasting or
 * importing a CSV. Rows added after page load only reach the server if their
 * inputs carry a name, and when they did not, a fifty-row import silently
 * arrived as two — the UI showed every row, so nothing looked wrong until the
 * publisher went looking for the other forty-eight.
 */
class BulkSiteRowSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $u = User::factory()->create(['email_verified_at' => now(), 'active_role_id' => $role->id]);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function bulkScript(): string
    {
        return file_get_contents(public_path('assets/js/publisher-websites-bulk.js'))
            ."\n"
            .file_get_contents(resource_path('views/publisher/websites.blade.php'));
    }

    public function test_rows_built_in_the_browser_are_given_submittable_names(): void
    {
        $script = $this->bulkScript();

        // The row template itself must carry names; a row that exists but cannot
        // be submitted is the exact failure this guards.
        $this->assertStringContainsString("name=\"sites[' + seq + '][url]\"", $script);
        $this->assertStringContainsString("name=\"sites[' + seq + '][price]\"", $script);
    }

    public function test_reindexing_does_not_require_an_existing_name(): void
    {
        $script = $this->bulkScript();

        // Selecting only on [name] meant reindexRows skipped every row it was
        // supposed to be numbering.
        $this->assertStringContainsString('tr.querySelector(\'input[type="url"]\')', $script);
        $this->assertStringContainsString('tr.querySelector(\'input[type="number"]\')', $script);
    }

    public function test_every_submitted_row_is_stored(): void
    {
        $publisher = $this->publisher();

        $sites = [];
        for ($i = 1; $i <= 25; $i++) {
            $sites[] = ['url' => "https://bulk-site-{$i}.example", 'price' => 50 + $i];
        }

        $this->actingAs($publisher)
            ->post(route('publisher.bulk-sites.request'), ['sites' => $sites])
            ->assertRedirect();

        $request = BulkSiteRequest::where('publisher_id', $publisher->id)->firstOrFail();

        $this->assertSame(25, $request->items()->count());
        $this->assertSame(25, (int) $request->estimated_count);
    }

    public function test_the_confirmation_states_how_many_were_saved(): void
    {
        $publisher = $this->publisher();

        $sites = [];
        for ($i = 1; $i <= 7; $i++) {
            $sites[] = ['url' => "https://counted-{$i}.example", 'price' => 80];
        }

        $this->actingAs($publisher)
            ->post(route('publisher.bulk-sites.request'), ['sites' => $sites])
            ->assertSessionHas('success', fn (string $msg) => str_contains($msg, '7 websites submitted'));
    }

    public function test_publisher_and_marketer_share_200_site_batch_limit(): void
    {
        $this->assertSame(200, BulkSiteRequest::MAX_SITES_PER_REQUEST);

        $script = $this->bulkScript();
        $this->assertStringContainsString('const MAX_ROWS =', $script);
        $this->assertStringContainsString('BulkSiteRequest::MAX_SITES_PER_REQUEST', $script);
        $this->assertStringContainsString('maxBulkRows', $script);

        $publisher = $this->publisher();
        $sites = [];
        for ($i = 1; $i <= 201; $i++) {
            $sites[] = ['url' => "https://over-limit-{$i}.example", 'price' => 40];
        }

        $this->actingAs($publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), ['sites' => $sites])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHasErrors('sites');

        $this->assertSame(0, BulkSiteRequest::where('publisher_id', $publisher->id)->count());

        $bulkShow = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString('MAX_SITES_PER_REQUEST', $bulkShow);
        $this->assertStringContainsString('-site batch limit', $bulkShow);

        $userIni = file_get_contents(public_path('.user.ini'));
        $this->assertStringContainsString('max_input_vars = 10000', $userIni);

        $composer = file_get_contents(base_path('composer.json'));
        $this->assertStringContainsString('max_input_vars=10000', $composer);
    }

    public function test_publisher_can_submit_exactly_200_sites(): void
    {
        $publisher = $this->publisher();
        $max = BulkSiteRequest::MAX_SITES_PER_REQUEST;

        $sites = [];
        for ($i = 1; $i <= $max; $i++) {
            $sites[] = ['url' => "https://exact-{$i}.example", 'price' => 50];
        }

        $this->actingAs($publisher)
            ->post(route('publisher.bulk-sites.request'), ['sites' => $sites])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = BulkSiteRequest::where('publisher_id', $publisher->id)->firstOrFail();
        $this->assertSame($max, $request->items()->count());
        $this->assertSame($max, (int) $request->estimated_count);
    }
}
