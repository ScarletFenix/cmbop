<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the semantic colour decisions numerically rather than by hex string,
 * so a future palette tweak cannot quietly drop below the WCAG floor.
 */
class ColorContrastGuardTest extends TestCase
{
    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];

        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->relativeLuminance($a);
        $lb = $this->relativeLuminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function token(string $name): string
    {
        $css = file_get_contents(public_path('assets/css/brand-colors.css'));
        preg_match('/'.preg_quote($name, '/').':\s*(#[0-9a-fA-F]{6})/', $css, $m);

        $this->assertNotEmpty($m, "Token {$name} not found");

        return $m[1];
    }

    public function test_contrast_helper_matches_known_values(): void
    {
        // Sanity-check the maths against published values before trusting it.
        $this->assertEqualsWithDelta(21.0, $this->contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, $this->contrast('#ffffff', '#ffffff'), 0.01);
    }

    public function test_primary_button_text_is_accessible(): void
    {
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrast('#ffffff', $this->token('--brand-primary'))
        );
    }

    public function test_secondary_button_border_meets_non_text_contrast(): void
    {
        $css = file_get_contents(public_path('assets/css/button-system.css'));
        preg_match('/--btn-secondary-border:\s*(#[0-9a-fA-F]{6})/', $css, $m);
        $this->assertNotEmpty($m, '--btn-secondary-border not found');

        // WCAG 1.4.11: a control boundary needs 3:1. #cbd5e1 was 1.48:1.
        $ratio = $this->contrast($m[1], '#ffffff');
        $this->assertGreaterThanOrEqual(
            3.0,
            $ratio,
            sprintf('Secondary border %s is only %.2f:1 on white', $m[1], $ratio)
        );
    }

    public function test_solid_toasts_use_brand_tokens_not_bootstrap_defaults(): void
    {
        $css = file_get_contents(public_path('assets/css/brand-colors.css'));

        $this->assertMatchesRegularExpression(
            '/\.toast\.bg-success\s*\{[^}]*background-color:\s*var\(--brand-success/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.toast\.bg-danger\s*\{[^}]*background-color:\s*var\(--brand-danger/s',
            $css
        );

        foreach (['--brand-success', '--brand-danger'] as $token) {
            $ratio = $this->contrast('#ffffff', $this->token($token));
            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf('White on %s is only %.2f:1', $token, $ratio)
            );
        }
    }

    public function test_toast_icons_distinguish_warning_from_info(): void
    {
        $partial = file_get_contents(resource_path('views/partials/app-toast.blade.php'));

        // Warning and info toasts are both flattened to white surfaces, so the
        // icon is the only signal separating them.
        $this->assertStringContainsString('fa-triangle-exclamation', $partial);
        $this->assertStringContainsString('fa-circle-info', $partial);
        $this->assertStringContainsString('fa-circle-check', $partial);
        $this->assertStringContainsString('fa-circle-exclamation', $partial);
        $this->assertStringContainsString('app-toast-icon', $partial);

        $css = file_get_contents(public_path('assets/css/brand-colors.css'));
        $this->assertStringContainsString('.app-toast-icon.text-brand-warning', $css);
        $this->assertStringContainsString('.app-toast-icon.text-brand-live', $css);

        // A meaningful icon still needs 3:1 against the white toast surface.
        foreach (['--brand-warning-ink', '--brand-live-hover'] as $token) {
            $ratio = $this->contrast($this->token($token), '#ffffff');
            $this->assertGreaterThanOrEqual(
                3.0,
                $ratio,
                sprintf('Toast icon colour %s is only %.2f:1 on white', $token, $ratio)
            );
        }
    }

    public function test_toast_message_wraps_beside_its_icon(): void
    {
        $partial = file_get_contents(resource_path('views/partials/app-toast.blade.php'));

        // flex-wrap moved the text onto its own line before letting it shrink.
        $this->assertStringNotContainsString('toast-body d-flex align-items-center flex-wrap', $partial);
        $this->assertStringContainsString('app-toast-message', $partial);
    }

    public function test_muted_body_text_clears_aa_on_every_surface(): void
    {
        $muted = $this->token('--brand-ink-muted');

        // Helper text runs 12-13px, so the 4.5:1 threshold applies — on the
        // white page and on both grey panel surfaces.
        foreach (['#ffffff', '--surface-2', '--surface-3'] as $surface) {
            $bg = str_starts_with($surface, '--') ? $this->token($surface) : $surface;
            $ratio = $this->contrast($muted, $bg);

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf('Muted text %s on %s is only %.2f:1', $muted, $bg, $ratio)
            );
        }
    }

    public function test_logo_grey_stays_the_identity_value(): void
    {
        // The logo grey is a brand mark, exempt from text contrast, and pinned
        // by DesignConsistencyTest. It must not be conflated with body copy.
        $this->assertSame('#76797c', $this->token('--brand-logo-grey'));
        $this->assertNotSame(
            $this->token('--brand-logo-grey'),
            $this->token('--brand-ink-muted'),
            'Body copy must not reuse the logo grey — it is below the AA floor.'
        );

        $css = file_get_contents(public_path('assets/css/brand-colors.css'));
        $this->assertStringNotContainsString(
            'color: var(--brand-neutral',
            $css,
            'Text should use --brand-ink-muted, not the identity grey.'
        );
    }

    public function test_dialogs_read_brand_tokens_instead_of_hardcoding_them(): void
    {
        $js = file_get_contents(public_path('js/slb-confirm.js'));

        $this->assertStringContainsString('getPropertyValue', $js);
        $this->assertStringContainsString("token('--brand-primary'", $js);
        $this->assertStringContainsString("token('--brand-danger'", $js);
        $this->assertStringContainsString("token('--brand-ink-muted'", $js);

        // The old literals had drifted: danger used the hover shade (#b91c1c)
        // and the cancel grey (#6b7280) existed nowhere else in the system.
        $this->assertStringNotContainsString("var DANGER = '#b91c1c'", $js);
        $this->assertStringNotContainsString("var MUTED = '#6b7280'", $js);
    }

    public function test_colour_files_live_only_in_the_assets_directory(): void
    {
        // This used to assert an assets/css <-> css mirror stayed in sync, which
        // contradicted PortalWrappingCssTest and briefly resurrected the dead
        // mirror during a merge — where single-select.css promptly drifted.
        // Nothing loads public/css, so there is one home for stylesheets.
        $this->assertDirectoryDoesNotExist(public_path('css'));

        foreach (['brand-colors.css', 'button-system.css'] as $file) {
            $this->assertFileExists(public_path('assets/css/'.$file));
        }
    }
}
