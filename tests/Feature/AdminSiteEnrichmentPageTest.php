<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Jobs\RefreshSiteMetricsJob;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Models\User;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSiteEnrichmentPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Attention Site',
            'site_url' => 'https://attention.example',
            'domain' => 'attention.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Enrichment attention fixture.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    private function makeRun(Site $site, array $overrides = []): SiteEnrichmentRun
    {
        return SiteEnrichmentRun::create(array_merge([
            'site_id' => $site->id,
            'type' => 'screenshot',
            'provider' => 'thum_io',
            'status' => 'failed',
            'error' => 'Capture failed',
            'triggered_by' => 'admin',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ], $overrides));
    }

    public function test_partial_screenshot_run_appears_and_links_to_edit(): void
    {
        $site = $this->makeSite(['site_name' => 'Partial Preview', 'domain' => 'partial-preview.example']);
        $this->makeRun($site, [
            'status' => 'partial',
            'error' => 'Screenshot refresh failed; previous preview kept.',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Needs attention', false)
            ->assertSee('Partial Preview', false)
            ->assertSee('partial', false)
            ->assertSee('queue:work --queue=default,emails', false)
            ->assertSee('function postEnrichmentJson', false)
            ->assertSee('window.location.reload()', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringContainsString('badge', $html);
    }

    public function test_two_failed_runs_for_one_site_show_one_row(): void
    {
        $site = $this->makeSite(['site_name' => 'Dup Fail', 'domain' => 'dup-fail.example']);
        $this->makeRun($site, [
            'type' => 'metrics',
            'status' => 'failed',
            'error' => 'Older failure',
            'created_at' => now()->subHour(),
        ]);
        $this->makeRun($site, [
            'type' => 'metrics',
            'status' => 'failed',
            'error' => 'Latest failure',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Latest failure', false)
            ->assertDontSee('Older failure', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Dup Fail'));
    }

    public function test_success_after_failure_drops_the_site_from_attention(): void
    {
        $site = $this->makeSite(['site_name' => 'Recovered Site', 'domain' => 'recovered.example']);
        $this->makeRun($site, ['status' => 'failed', 'error' => 'Was broken']);
        $this->makeRun($site, ['status' => 'success', 'error' => null]);

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertDontSee('Recovered Site', false)
            ->assertDontSee('Was broken', false);
    }

    public function test_status_and_type_filters(): void
    {
        $metrics = $this->makeSite(['site_name' => 'Metrics Fail', 'domain' => 'metrics-fail.example']);
        $shot = $this->makeSite(['site_name' => 'Shot Partial', 'domain' => 'shot-partial.example']);
        $this->makeRun($metrics, ['type' => 'metrics', 'status' => 'failed', 'error' => 'Metrics down']);
        $this->makeRun($shot, ['type' => 'screenshot', 'status' => 'partial', 'error' => 'Placeholder stored']);

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index', ['status' => 'partial']))
            ->assertOk()
            ->assertSee('Shot Partial', false)
            ->assertDontSee('Metrics Fail', false);

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index', ['type' => 'metrics']))
            ->assertOk()
            ->assertSee('Metrics Fail', false)
            ->assertDontSee('Shot Partial', false);
    }

    public function test_disabled_enrichment_does_not_queue_work(): void
    {
        Queue::fake();
        config(['site_enrichment.enabled' => false]);
        $site = $this->makeSite();

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.enrich', $site->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Site enrichment is disabled (SITE_ENRICHMENT_ENABLED=false).');

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.refresh-metrics', $site->id))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.refresh-screenshot', $site->id))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(route('admin.site-enrichment.rerun-failed'), ['limit' => 5])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    public function test_disabled_job_handle_does_not_create_runs(): void
    {
        config([
            'site_enrichment.enabled' => false,
            'site_enrichment.default_provider' => 'manual',
            'site_enrichment.screenshots.provider' => 'none',
        ]);
        $site = $this->makeSite();

        (new EnrichSiteJob($site->id, 'test'))->handle(app(SiteEnrichmentService::class));
        (new RefreshSiteMetricsJob($site->id, 'test'))->handle(app(SiteEnrichmentService::class));
        (new CaptureSiteScreenshotJob($site->id, 'test'))->handle(app(SiteEnrichmentService::class));

        $this->assertSame(0, SiteEnrichmentRun::query()->count());
    }

    public function test_jobs_are_unique_per_site_and_type(): void
    {
        $enrich = new EnrichSiteJob(12, 'admin');
        $metrics = new RefreshSiteMetricsJob(12, 'admin');
        $shot = new CaptureSiteScreenshotJob(12, 'admin');

        $this->assertSame('enrich:12', $enrich->uniqueId());
        $this->assertSame('metrics:12', $metrics->uniqueId());
        $this->assertSame('screenshot:12', $shot->uniqueId());
        $this->assertSame(180, $enrich->timeout);
        $this->assertSame(90, $metrics->timeout);
        $this->assertSame(90, $shot->timeout);
        $this->assertSame(2, $enrich->tries);
    }

    public function test_dashboard_queue_includes_partial_latest_run(): void
    {
        $site = $this->makeSite(['site_name' => 'Dash Partial', 'domain' => 'dash-partial.example']);
        $this->makeRun($site, ['status' => 'failed', 'error' => 'Old fail']);
        $this->makeRun($site, [
            'status' => 'partial',
            'error' => 'Placeholder stored',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('enrichment.0.site_name', 'Dash Partial')
            ->assertJsonPath('enrichment.0.status', 'partial')
            ->assertJsonPath('enrichment.0.url', route('admin.sites.edit', $site->id));
    }
}
