<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Hover used to be 99 independent decisions: four different row tints, two
 * rules that moved the layout, hover on things that were not controls, and
 * nothing gated for touch. This pins the system that replaced them.
 */
class HoverSystemTest extends TestCase
{
    private function hoverSystem(): string
    {
        return file_get_contents(public_path('assets/css/hover-system.css'));
    }

    /**
     * @return list<string>
     */
    private function stylesheets(): array
    {
        return glob(public_path('assets/css/*.css'));
    }

    /**
     * @return list<string>
     */
    private function layouts(): array
    {
        return [
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
            'layouts/app.blade.php',
        ];
    }

    public function test_the_hover_scale_is_defined_once(): void
    {
        $css = $this->hoverSystem();

        foreach (['--hover-1:', '--hover-2:', '--hover-3:', '--hover-ink:', '--elevation-hover:', '--motion-hover:'] as $token) {
            $this->assertStringContainsString($token, $css, "Missing token {$token}");
        }
    }

    public function test_legacy_hover_names_point_at_the_new_scale(): void
    {
        $css = $this->hoverSystem();

        // Roughly sixty existing rules reference these, so re-pointing them is
        // what re-tunes the app without editing each rule.
        $this->assertMatchesRegularExpression('/--hover-overlay:\s*var\(--hover-2\)/', $css);
        $this->assertMatchesRegularExpression('/--hover-overlay-strong:\s*var\(--hover-3\)/', $css);
        $this->assertMatchesRegularExpression('/--hover-tint:\s*var\(--hover-2\)/', $css);
        $this->assertMatchesRegularExpression('/--hover-tint-strong:\s*var\(--hover-3\)/', $css);
    }

    public function test_every_layout_loads_the_hover_system_last(): void
    {
        foreach ($this->layouts() as $layout) {
            $markup = file_get_contents(resource_path('views/'.$layout));

            // Only <link> tags decide the cascade — prose mentioning a
            // stylesheet does not.
            preg_match_all('/<link[^>]+assets\/css\/([a-z-]+)\.css/', $markup, $matches);
            $sheets = $matches[1];

            $this->assertContains('hover-system', $sheets, "{$layout} must load the hover system.");

            // It has to win on order, otherwise it needs !important to be heard.
            $this->assertSame(
                'hover-system',
                end($sheets),
                "{$layout} loads ".end($sheets).'.css after the hover system.'
            );
        }
    }

    public function test_decorative_hover_is_gated_so_it_does_not_latch_on_touch(): void
    {
        $css = $this->hoverSystem();

        $this->assertStringContainsString('@media (hover: hover) and (pointer: fine)', $css);

        // Press feedback must stay outside the query so touch still responds.
        $activeBlock = substr($css, strpos($css, ':active'));
        $this->assertStringContainsString('--hover-3', $activeBlock);
    }

    public function test_the_four_competing_row_tints_are_gone(): void
    {
        $catalog = file_get_contents(public_path('assets/css/catalog.css'));

        // The same gesture used to produce a different colour per table.
        foreach (['#f5f9ff', '#ffe6e6', '#f9f9f9'] as $tint) {
            $this->assertStringNotContainsString($tint, $catalog, "Row hover tint {$tint} should come from the scale now.");
        }
    }

    public function test_the_catalog_buy_button_reacts_to_hover(): void
    {
        $catalog = file_get_contents(public_path('assets/css/catalog.css'));

        // It used to pin itself to the resting colour, leaving the main CTA as
        // the one button in the catalog with no hover feedback.
        $this->assertStringNotContainsString('.buy-now:hover', $catalog);
        $this->assertStringContainsString('.buy-now:hover', $this->hoverSystem());
    }

    public function test_hover_never_moves_the_layout(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $file) {
            $css = file_get_contents($file);

            // A hover rule that translates shifts whatever sits beside it.
            preg_match_all('/([^{}]*:hover[^{}]*)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

            foreach ($matches as $rule) {
                if (! preg_match('/transform:\s*translate/i', $rule[2])) {
                    continue;
                }
                // Large standalone cards may still lift; chips in a row may not.
                if (preg_match('/\.slb-feature|\.pricing-card|\.slb-step/', $rule[1])) {
                    continue;
                }
                $offenders[] = basename($file).' — '.trim(preg_replace('/\s+/', ' ', $rule[1]));
            }
        }

        $this->assertSame([], $offenders, "Hover must not move text-bearing controls:\n".implode("\n", $offenders));
    }

    public function test_chips_in_a_row_no_longer_lift(): void
    {
        $catalog = file_get_contents(public_path('assets/css/catalog.css'));

        $this->assertDoesNotMatchRegularExpression(
            '/\.site-chip:hover\s*\{[^}]*translateY/',
            $catalog,
            'A row can carry several chips, so lifting each one read as jitter.'
        );
    }

    public function test_the_auth_cta_uses_depth_instead_of_a_nudge(): void
    {
        $auth = file_get_contents(public_path('assets/css/auth-pages.css'));

        $this->assertDoesNotMatchRegularExpression(
            '/\.auth-cta:hover\s*\{[^}]*transform/',
            $auth
        );
        $this->assertMatchesRegularExpression('/\.auth-cta:hover\s*\{[^}]*box-shadow/', $auth);
    }

    public function test_focus_mirrors_hover_on_the_controls_that_had_none(): void
    {
        $css = $this->hoverSystem();

        // These had hover styling but no focus indicator at all.
        foreach ([
            '.btn-chip:focus-visible',
            '.btn-cta-tertiary:focus-visible',
            '.site-status-filter:focus-visible',
            '.auth-secondary-btn:focus-visible',
            '.navbar-cta-primary:focus-visible',
            '.nc-filter:focus-visible',
            '.nc-bell-btn:focus-visible',
            '#sidebar a:focus-visible',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} needs a focus indicator.");
        }

        $this->assertStringContainsString('var(--focus-ring)', $css);
    }

    public function test_the_focus_ring_is_brand_teal_not_sky_blue(): void
    {
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));

        // #0ea5e9 appears on no other control, so the ring looked borrowed.
        $this->assertMatchesRegularExpression('/--focus-ring:\s*rgba\(26,\s*88,\s*94/', $brand);
        $this->assertStringNotContainsString('--focus-ring: rgba(14, 165, 233', $brand);
        $this->assertMatchesRegularExpression('/--bs-focus-ring-color:\s*var\(--focus-ring\)/', $brand);
    }

    public function test_links_gain_an_underline_on_hover_rather_than_losing_one(): void
    {
        $css = $this->hoverSystem();

        $this->assertMatchesRegularExpression('/a:not\(\.btn\).*:hover\s*\{\s*text-decoration:\s*underline/s', $css);
    }

    public function test_interaction_timing_uses_one_token(): void
    {
        $strays = [];

        foreach ($this->stylesheets() as $file) {
            foreach (file($file) as $i => $line) {
                if (! str_contains($line, 'transition')) {
                    continue;
                }
                // Entrance and collapse animations are a separate concern; only
                // hover/press feedback has to share the token.
                if (preg_match('/max-height|opacity|transform \d|\bheight\b/', $line)) {
                    continue;
                }
                if (preg_match('/\b0?\.2s|\b0?\.18s|\b0?\.25s\b/', $line)) {
                    $strays[] = basename($file).':'.($i + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $strays, "Hover timing should use --motion-hover:\n".implode("\n", $strays));
    }

    public function test_reduced_motion_is_respected(): void
    {
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $this->hoverSystem());
    }
}
