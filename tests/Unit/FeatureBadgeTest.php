<?php

namespace Tests\Unit;

use App\Support\FeatureBadge;
use Tests\TestCase;

class FeatureBadgeTest extends TestCase
{
    public function test_active_when_until_is_today(): void
    {
        config(['feature_badges.demo.item' => [
            'label' => 'New',
            'until' => now()->toDateString(),
        ]]);

        $this->assertTrue(FeatureBadge::active('demo.item'));
        $this->assertSame('New', FeatureBadge::label('demo.item'));
    }

    public function test_inactive_after_until(): void
    {
        config(['feature_badges.demo.item' => [
            'label' => 'New',
            'until' => now()->subDay()->toDateString(),
        ]]);

        $this->assertFalse(FeatureBadge::active('demo.item'));
    }

    public function test_disabled_flag_hides_badge(): void
    {
        config(['feature_badges.demo.item' => [
            'label' => 'New',
            'enabled' => false,
        ]]);

        $this->assertFalse(FeatureBadge::active('demo.item'));
    }

    public function test_missing_key_is_inactive(): void
    {
        $this->assertFalse(FeatureBadge::active('does.not.exist'));
        $this->assertSame('New', FeatureBadge::label('does.not.exist'));
    }
}
