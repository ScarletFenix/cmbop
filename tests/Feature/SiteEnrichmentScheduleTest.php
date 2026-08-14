<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SiteEnrichmentScheduleTest extends TestCase
{
    public function test_scheduled_enrich_queues_jobs_instead_of_running_sync(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'sites:enrich'));

        $this->assertNotNull($scheduled, 'sites:enrich must be scheduled.');
        $this->assertStringContainsString('sites:enrich --stale', (string) $scheduled->command);
        $this->assertStringNotContainsString('--sync', (string) $scheduled->command);
    }
}
