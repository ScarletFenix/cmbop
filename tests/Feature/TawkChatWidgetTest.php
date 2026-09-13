<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TawkChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_embeds_tawk_and_hides_help_fab_when_configured(): void
    {
        config([
            'services.tawk.property_id' => '6aa6a3693d02a53444168308',
            'services.tawk.widget_id' => 'default',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://embed.tawk.to/6aa6a3693d02a53444168308/default', false)
            ->assertSee('Tawk_API', false)
            ->assertDontSee('aria-label="Open help and feedback"', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_public_home_keeps_help_fab_when_tawk_is_off(): void
    {
        config([
            'services.tawk.property_id' => '',
            'services.tawk.widget_id' => '',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('aria-label="Open help and feedback"', false)
            ->assertDontSee('embed.tawk.to', false);
    }

    public function test_public_layout_includes_tawk_partial(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('partials.tawk', $layout);
        $this->assertStringContainsString('TawkChat::enabled()', $layout);
        $this->assertStringNotContainsString('advertiser/layouts', $layout);
    }
}
