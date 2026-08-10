<?php

namespace Tests\Unit;

use App\Models\Site;
use Tests\TestCase;

class SiteGoodMetricsTest extends TestCase
{
    public function test_has_good_metrics_uses_shared_thresholds(): void
    {
        $site = new Site([
            'da' => Site::GOOD_MIN_DA,
            'dr' => Site::GOOD_MIN_DR,
            'traffic' => Site::GOOD_MIN_TRAFFIC,
        ]);
        $this->assertTrue($site->hasGoodMetrics());

        $weak = new Site([
            'da' => Site::GOOD_MIN_DA - 1,
            'dr' => Site::GOOD_MIN_DR,
            'traffic' => Site::GOOD_MIN_TRAFFIC,
        ]);
        $this->assertFalse($weak->hasGoodMetrics());
    }

    public function test_with_good_metrics_scope_matches_helper_thresholds(): void
    {
        $this->assertSame(30, Site::GOOD_MIN_DA);
        $this->assertSame(30, Site::GOOD_MIN_DR);
        $this->assertSame(10000, Site::GOOD_MIN_TRAFFIC);

        $sql = Site::query()->withGoodMetrics()->toSql();
        $this->assertMatchesRegularExpression('/["`]?da["`]?\s*>=/i', $sql);
        $this->assertMatchesRegularExpression('/["`]?dr["`]?\s*>=/i', $sql);
        $this->assertMatchesRegularExpression('/["`]?traffic["`]?\s*>=/i', $sql);

        $bindings = Site::query()->withGoodMetrics()->getBindings();
        $this->assertContains(Site::GOOD_MIN_DA, $bindings);
        $this->assertContains(Site::GOOD_MIN_DR, $bindings);
        $this->assertContains(Site::GOOD_MIN_TRAFFIC, $bindings);
    }
}
