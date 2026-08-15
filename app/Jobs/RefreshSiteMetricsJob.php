<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshSiteMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(
        public int $siteId,
        public string $triggeredBy = 'system',
        public ?string $provider = null,
    ) {}

    public function uniqueId(): string
    {
        return 'metrics:'.$this->siteId;
    }

    public function handle(SiteEnrichmentService $enrichment): void
    {
        if (! SiteEnrichmentService::enabled()) {
            return;
        }

        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $enrichment->refreshMetrics($site, $this->triggeredBy, $this->provider);
    }
}
