<?php

namespace Tests\Unit;

use App\Models\Project;
use Tests\TestCase;

class ProjectPlacementStagesTest extends TestCase
{
    public function test_host_from_url_strips_scheme_www_and_path(): void
    {
        $this->assertSame('acme.example', Project::hostFromUrl('https://www.acme.example/blog/post'));
        $this->assertSame('acme.example', Project::hostFromUrl('http://acme.example'));
        $this->assertSame('acme.example', Project::hostFromUrl('acme.example/path'));
        $this->assertSame('', Project::hostFromUrl(null));
        $this->assertSame('', Project::hostFromUrl('   '));
    }

    public function test_stage_bucket_maps_advertiser_stages(): void
    {
        $this->assertSame('not_started', Project::stageBucket('awaiting_payment'));
        $this->assertSame('not_started', Project::stageBucket('scheduled'));
        $this->assertSame('not_started', Project::stageBucket('paid'));
        $this->assertSame('in_progress', Project::stageBucket('processing'));
        $this->assertSame('waiting_approval', Project::stageBucket('url_delivered'));
        $this->assertSame('needs_improvements', Project::stageBucket('revision'));
        $this->assertSame('needs_improvements', Project::stageBucket('content_revision'));
        $this->assertSame('completed', Project::stageBucket('completed'));
        $this->assertSame('rejected', Project::stageBucket('cancelled'));
        $this->assertSame('rejected', Project::stageBucket('refunded'));
        $this->assertSame('rejected', Project::stageBucket('payment_failed'));
        $this->assertNull(Project::stageBucket('unknown'));
    }
}
