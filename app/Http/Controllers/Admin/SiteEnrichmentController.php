<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Jobs\RefreshSiteMetricsJob;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Services\ActivityLogger;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use App\Services\SiteEnrichment\SiteMetricsAggregator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteEnrichmentController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->attentionStatusFilter($request->query('status'));
        $type = $this->attentionTypeFilter($request->query('type'));
        $attention = new LengthAwarePaginator([], 0, 40);
        $attention->withPath($request->url())->appends($request->only(['status', 'type']));

        try {
            if (Schema::hasTable('site_enrichment_runs')) {
                $siteSelect = $this->siteRelationSelectColumns();
                $attention = SiteEnrichmentRun::query()
                    ->with(['site:'.implode(',', $siteSelect)])
                    ->needsAttention($status, $type)
                    ->latest('id')
                    ->paginate(40)
                    ->withQueryString();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment attention list failed', [
                'error' => $e->getMessage(),
            ]);
            $attention = new LengthAwarePaginator([], 0, 40);
            $attention->withPath($request->url())->appends($request->only(['status', 'type']));
        }

        $aggregator = app(SiteMetricsAggregator::class);
        $config = [
            'enabled' => (bool) config('site_enrichment.enabled'),
            'default_provider' => (string) config('site_enrichment.default_provider'),
            'fallback_providers' => config('site_enrichment.fallback_providers'),
            'has_api_keys' => $aggregator->anyApiProviderConfigured(),
            'refresh_frequency' => (string) config('site_enrichment.refresh_frequency'),
            'max_age_days' => (int) config('site_enrichment.max_age_days'),
            'screenshot_provider' => $this->screenshotProviderLabel(),
        ];

        $staleSites = new LengthAwarePaginator([], 0, 40);
        $staleSites->withPath($request->url())->appends($request->query());
        $staleCount = 0;
        $placeholderSiteIds = [];
        $batchLimit = max(1, (int) config('site_enrichment.batch_limit', 40));

        try {
            $staleQuery = Site::query()
                ->where('active', 1)
                ->staleForEnrichment()
                ->orderForStaleEnrichment();

            if (Schema::hasTable('site_enrichment_runs')) {
                $staleQuery->with('latestEnrichmentRun');
                $placeholderSiteIds = array_fill_keys(
                    SiteEnrichmentRun::placeholderScreenshotSiteIds(),
                    true
                );
            }

            $staleSites = $staleQuery
                ->paginate(40, ['*'], 'stale_page')
                ->withQueryString();
            $staleCount = $staleSites->total();
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment stale list failed', [
                'error' => $e->getMessage(),
            ]);
            $staleSites = new LengthAwarePaginator([], 0, 40);
            $staleSites->withPath($request->url())->appends($request->query());
        }

        return view('admin.site-enrichment', compact(
            'attention',
            'config',
            'staleCount',
            'staleSites',
            'placeholderSiteIds',
            'batchLimit',
            'status',
            'type'
        ));
    }

    public function refreshMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        $sync = $request->boolean('sync', false);

        if ($sync) {
            $run = $enrichment->refreshMetrics($site, 'admin', $request->input('provider'));
        } else {
            RefreshSiteMetricsJob::dispatch($site->id, 'admin', $request->input('provider'));
            $run = null;
        }

        ActivityLogger::log(
            'site.metrics_refreshed',
            auth()->user()->name.' refreshed metrics for "'.$site->site_name.'"',
            $site,
            ['provider' => $request->input('provider'), 'sync' => $sync],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Metrics refreshed' : 'Metrics refresh queued',
            'run' => $run,
            'site' => $site->fresh(),
        ]);
    }

    public function refreshScreenshot(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        // Default async — Sites Management must not block on remote capture.
        $sync = $request->boolean('sync', false);

        if ($sync) {
            $run = $enrichment->refreshScreenshot($site, 'admin');
        } else {
            CaptureSiteScreenshotJob::dispatch($site->id, 'admin');
            $run = null;
        }

        ActivityLogger::log(
            'site.screenshot_refreshed',
            auth()->user()->name.' refreshed screenshot for "'.$site->site_name.'"',
            $site,
            ['sync' => $sync],
            $site->site_name
        );

        $fresh = $site->fresh();
        $usedPlaceholder = (bool) data_get($run, 'payload.used_placeholder', false);
        $runStatus = (string) data_get($run, 'status', '');
        $providerError = trim((string) (
            data_get($run, 'error')
            ?? $fresh?->enrichment_error
            ?? ''
        ));
        // Placeholder / partial captures look like success in the UI but leave a
        // broken preview — treat them as failures so staff upload a site image.
        $ok = $sync
            ? (! $usedPlaceholder && $runStatus === 'success')
            : true;

        $message = $sync
            ? ($ok ? 'Screenshot refreshed' : ($providerError !== '' ? $providerError : 'Screenshot capture failed. Upload a site image instead.'))
            : 'Screenshot refresh queued';

        return response()->json([
            'success' => $ok,
            'message' => $message,
            'run' => $run,
            'site' => $fresh,
        ], $ok ? 200 : 422);
    }

    public function enrich(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        // Default async — Manage → Enrich should return immediately.
        $sync = $request->boolean('sync', false);

        if ($sync) {
            $enrichment->enrich($site, 'admin', true, true);
        } else {
            EnrichSiteJob::dispatch($site->id, 'admin', true, true);
        }

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Site enriched' : 'Enrichment queued',
            'site' => $site->fresh(),
        ]);
    }

    public function manualMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }

        $data = $request->validate([
            'dr' => 'nullable|integer|min:0|max:100',
            'da' => 'nullable|integer|min:0|max:100',
            'traffic' => 'nullable|integer|min:0|max:4294967295',
        ]);

        $run = $enrichment->applyManualMetrics(
            $site,
            isset($data['dr']) ? (int) $data['dr'] : null,
            isset($data['da']) ? (int) $data['da'] : null,
            isset($data['traffic']) ? (int) $data['traffic'] : null,
            'admin'
        );

        ActivityLogger::log(
            'site.metrics_manual',
            auth()->user()->name.' set manual metrics for "'.$site->site_name.'"',
            $site,
            $data,
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Manual metrics saved',
            'run' => $run,
            'site' => $site->fresh(),
        ]);
    }

    public function allowApiOverwrite(Request $request, int $id)
    {
        if (! Site::hasSitesColumn('metrics_manual')) {
            return $request->wantsJson()
                ? response()->json([
                    'success' => false,
                    'message' => 'Manual metrics lock is unavailable until the database migration has been run.',
                ], 422)
                : back()->withErrors(['metrics_manual' => 'Manual metrics lock is unavailable until the database migration has been run.']);
        }

        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            if ($request->wantsJson()) {
                return $denied;
            }

            return back()->withErrors(['metrics_manual' => (string) data_get($denied->getData(true), 'message', 'Not allowed.')]);
        }

        $site->forceFill(['metrics_manual' => false])->save();

        ActivityLogger::log(
            'site.metrics_api_unlocked',
            auth()->user()->name.' allowed API overwrite for "'.$site->site_name.'"',
            $site,
            [],
            $site->site_name
        );

        $message = 'API overwrite allowed. Queue Enrich to fetch live metrics.';

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'site' => $site->fresh(),
            ]);
        }

        return back()->with('success', $message);
    }

    public function queueStale(Request $request)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }

        $configured = max(1, (int) config('site_enrichment.batch_limit', 40));
        $limit = min($configured, max(1, (int) $request->input('limit', $configured)));

        try {
            $ids = Site::query()
                ->where('active', 1)
                ->staleForEnrichment()
                ->orderForStaleEnrichment()
                ->limit($limit)
                ->pluck('id');

            foreach ($ids as $siteId) {
                EnrichSiteJob::dispatch((int) $siteId, 'admin', true, true);
            }

            return response()->json([
                'success' => true,
                'message' => 'Queued '.$ids->count().' stale site(s)',
                'count' => $ids->count(),
                'site_ids' => $ids->values()->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment queueStale failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not queue stale sites. Please try again after migrations are applied.',
                'count' => 0,
            ], 422);
        }
    }

    public function rerunFailed(Request $request)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }

        if (! Schema::hasTable('site_enrichment_runs')) {
            return response()->json([
                'success' => false,
                'message' => 'Enrichment history is unavailable until the database migration has been run.',
                'count' => 0,
            ], 422);
        }

        try {
            $limit = min(100, max(1, (int) $request->input('limit', 20)));
            $ids = SiteEnrichmentRun::query()
                ->needsAttention()
                ->latest('id')
                ->limit($limit)
                ->pluck('site_id')
                ->unique()
                ->filter();

            foreach ($ids as $siteId) {
                EnrichSiteJob::dispatch((int) $siteId, 'admin', true, true);
            }

            return response()->json([
                'success' => true,
                'message' => 'Queued '.$ids->count().' site(s) for re-scan',
                'count' => $ids->count(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment rerunFailed failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not queue failed scans. Please try again after migrations are applied.',
                'count' => 0,
            ], 422);
        }
    }

    /**
     * Columns for SiteEnrichmentRun→site eager load (skip Hostinger-missing cols).
     *
     * @return list<string>
     */
    private function siteRelationSelectColumns(): array
    {
        $columns = ['id', 'site_name', 'domain', 'site_url'];
        foreach (['enrichment_status', 'metrics_fetched_at', 'screenshot_fetched_at'] as $optional) {
            if (Site::hasSitesColumn($optional)) {
                $columns[] = $optional;
            }
        }

        return $columns;
    }

    private function screenshotProviderLabel(): string
    {
        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');

        if ($provider === 'thum_io') {
            return 'thum_io (unauthenticated)';
        }

        if ($provider === 'screenshotone') {
            return filled(config('site_enrichment.screenshots.screenshotone_access_key'))
                ? 'screenshotone'
                : 'screenshotone (no key)';
        }

        return $provider;
    }

    private function denyIfEnrichmentDisabled()
    {
        if (SiteEnrichmentService::enabled()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Site enrichment is disabled (SITE_ENRICHMENT_ENABLED=false).',
        ], 422);
    }

    private function attentionStatusFilter(mixed $status): ?string
    {
        $status = is_string($status) ? strtolower(trim($status)) : '';

        return in_array($status, SiteEnrichmentRun::ATTENTION_STATUSES, true) ? $status : null;
    }

    private function attentionTypeFilter(mixed $type): ?string
    {
        $type = is_string($type) ? strtolower(trim($type)) : '';

        return in_array($type, ['metrics', 'screenshot'], true) ? $type : null;
    }

    private function denyMarketingLockedListing(Request $request, Site $site)
    {
        $user = $request->user();
        if ($user?->isMarketing() && ! $user?->isAdmin() && $site->isLockedForMarketingEdits()) {
            return response()->json([
                'success' => false,
                'message' => 'Marketing can only edit pending sites that are not live.',
            ], 403);
        }

        return null;
    }
}
