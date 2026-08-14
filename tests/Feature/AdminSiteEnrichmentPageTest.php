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
            'metrics_fetched_at' => now()->subDay(),
            'screenshot_path' => 'site-screenshots/fixture.jpg',
            'screenshot_fetched_at' => now()->subDay(),
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

        $this->actingAs($this->admin)
            ->postJson(route('admin.site-enrichment.queue-stale'), ['limit' => 5])
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

    public function test_stale_list_matches_card_count_and_excludes_fresh_sites(): void
    {
        $missingMetrics = $this->makeSite([
            'site_name' => 'No Metrics Yet',
            'domain' => 'no-metrics.example',
            'site_url' => 'https://no-metrics.example',
            'metrics_fetched_at' => null,
        ]);
        $this->makeSite([
            'site_name' => 'Fresh Catalog Site',
            'domain' => 'fresh-catalog.example',
            'site_url' => 'https://fresh-catalog.example',
        ]);
        $this->makeSite([
            'site_name' => 'Inactive Missing',
            'domain' => 'inactive-missing.example',
            'site_url' => 'https://inactive-missing.example',
            'active' => false,
            'metrics_fetched_at' => null,
        ]);

        $expected = Site::query()->where('active', 1)->staleForEnrichment()->count();
        $this->assertSame(1, $expected);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Stale sites', false)
            ->assertSee('No Metrics Yet', false)
            ->assertSee('No metrics', false)
            ->assertSee('href="#stale-sites"', false)
            ->assertSee('Queue stale (1)', false)
            ->assertDontSee('Fresh Catalog Site', false)
            ->assertDontSee('Inactive Missing', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-stale-site-id="'.$missingMetrics->id.'"'));
        $this->assertSame($expected, substr_count($html, 'data-stale-site-id='));
        $this->assertStringContainsString(route('admin.sites.edit', $missingMetrics->id), $html);
    }

    public function test_placeholder_screenshot_is_stale_even_when_path_is_set(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Placeholder Preview',
            'domain' => 'placeholder-preview.example',
            'site_url' => 'https://placeholder-preview.example',
        ]);
        $this->makeRun($site, [
            'status' => 'partial',
            'error' => 'Screenshot refresh failed; previous preview kept.',
            'payload' => ['used_placeholder' => true],
        ]);

        $this->assertTrue(Site::query()->whereKey($site->id)->staleForEnrichment()->exists());

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Placeholder Preview', false)
            ->assertSee('Placeholder screenshot', false);
    }

    public function test_failed_run_with_fresh_data_is_not_in_stale_list(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Failed But Fresh',
            'domain' => 'failed-but-fresh.example',
            'site_url' => 'https://failed-but-fresh.example',
        ]);
        $this->makeRun($site, [
            'type' => 'metrics',
            'status' => 'failed',
            'error' => 'Provider timeout',
        ]);

        $this->assertFalse(Site::query()->whereKey($site->id)->staleForEnrichment()->exists());

        $html = $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Failed But Fresh', false)
            ->assertSee('Needs attention', false)
            ->getContent();

        $this->assertSame(0, substr_count($html, 'data-stale-site-id="'.$site->id.'"'));
    }

    public function test_queue_stale_dispatches_unique_enrich_jobs(): void
    {
        Queue::fake();
        config(['site_enrichment.batch_limit' => 2]);

        $first = $this->makeSite([
            'site_name' => 'Stale One',
            'domain' => 'stale-one.example',
            'site_url' => 'https://stale-one.example',
            'metrics_fetched_at' => null,
        ]);
        $second = $this->makeSite([
            'site_name' => 'Stale Two',
            'domain' => 'stale-two.example',
            'site_url' => 'https://stale-two.example',
            'screenshot_path' => null,
        ]);
        $this->makeSite([
            'site_name' => 'Stale Three Over Limit',
            'domain' => 'stale-three.example',
            'site_url' => 'https://stale-three.example',
            'metrics_fetched_at' => null,
        ]);
        $fresh = $this->makeSite([
            'site_name' => 'Not Stale',
            'domain' => 'not-stale.example',
            'site_url' => 'https://not-stale.example',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.site-enrichment.queue-stale'), ['limit' => 2])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 2);

        Queue::assertPushed(EnrichSiteJob::class, 2);
        Queue::assertPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($first) {
            return $job->siteId === $first->id
                && $job->triggeredBy === 'admin'
                && $job->metrics
                && $job->screenshot;
        });
        Queue::assertPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($second) {
            return $job->siteId === $second->id;
        });
        Queue::assertNotPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($fresh) {
            return $job->siteId === $fresh->id;
        });
    }

    public function test_config_cards_show_when_apis_are_missing(): void
    {
        config([
            'site_enrichment.default_provider' => 'manual',
            'site_enrichment.providers.ahrefs.api_token' => '',
            'site_enrichment.providers.moz.access_token' => '',
            'site_enrichment.providers.moz.access_id' => '',
            'site_enrichment.providers.moz.secret_key' => '',
            'site_enrichment.providers.semrush.api_key' => '',
            'site_enrichment.screenshots.provider' => 'thum_io',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('no API keys', false)
            ->assertSee('thum_io (unauthenticated)', false)
            ->assertSee('manual lock skips API providers', false);
    }

    public function test_stale_row_can_unlock_manual_metrics(): void
    {
        $site = $this->makeSite([
            'site_name' => 'Locked Stale',
            'domain' => 'locked-stale.example',
            'site_url' => 'https://locked-stale.example',
            'metrics_manual' => true,
            'metrics_fetched_at' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Allow API overwrite', false)
            ->assertSee('Locked Stale', false);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.allow-api-metrics', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse((bool) $site->fresh()->metrics_manual);
    }

    public function test_sites_enrich_stale_uses_the_shared_scope(): void
    {
        Queue::fake();

        $stale = $this->makeSite([
            'site_name' => 'Command Stale',
            'domain' => 'command-stale.example',
            'site_url' => 'https://command-stale.example',
            'metrics_fetched_at' => null,
        ]);
        $fresh = $this->makeSite([
            'site_name' => 'Command Fresh',
            'domain' => 'command-fresh.example',
            'site_url' => 'https://command-fresh.example',
        ]);
        $failedOnly = $this->makeSite([
            'site_name' => 'Command Failed Fresh',
            'domain' => 'command-failed-fresh.example',
            'site_url' => 'https://command-failed-fresh.example',
        ]);
        $this->makeRun($failedOnly, [
            'type' => 'metrics',
            'status' => 'failed',
            'error' => 'Old provider error',
        ]);

        $this->artisan('sites:enrich', ['--stale' => true])
            ->assertSuccessful();

        Queue::assertPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($stale) {
            return $job->siteId === $stale->id && $job->triggeredBy === 'schedule';
        });
        Queue::assertNotPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($fresh) {
            return $job->siteId === $fresh->id;
        });
        Queue::assertNotPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($failedOnly) {
            return $job->siteId === $failedOnly->id;
        });
    }
}
