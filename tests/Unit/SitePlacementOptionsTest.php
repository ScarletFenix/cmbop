<?php

namespace Tests\Unit;

use App\Models\Site;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePlacementOptionsTest extends TestCase
{
    #[Test]
    public function homepage_and_social_options_read_attributes_without_schema_gate(): void
    {
        $site = new Site;
        $site->forceFill([
            'homepage_placement_prices' => ['1' => 0, '7' => 25, '30' => 80],
            'social_promotion' => [
                'facebook' => true,
                'instagram' => true,
                'x' => false,
            ],
        ]);

        $this->assertSame([1 => 0.0, 7 => 25.0, 30 => 80.0], $site->homepagePlacementOptions());
        $this->assertSame(['facebook', 'instagram'], $site->enabledSocialChannels());
        $this->assertTrue($site->offersHomepagePlacement());
        $this->assertTrue($site->offersSocialPromotion());
        $this->assertSame(1, $site->longestFreeHomepageDays());
    }

    #[Test]
    public function hostinger_sql_includes_homepage_social_columns(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/add_homepage_social_placement.sql'));
        $recent = (string) file_get_contents(base_path('database/sql/hostinger_recent_tables.sql'));

        foreach (['homepage_placement_prices', 'social_promotion', 'homepage_days', 'social_channels'] as $column) {
            $this->assertStringContainsString($column, $sql);
            $this->assertStringContainsString($column, $recent);
        }
    }
}
