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

class EnrichSiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public int $siteId,
        public string $triggeredBy = 'system',
        public bool $metrics = true,
        public bool $screenshot = true,
    ) {}

    public function uniqueId(): string
    {
        return 'enrich:'.$this->siteId;
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

        $enrichment->enrich($site, $this->triggeredBy, $this->metrics, $this->screenshot);
    }
}
