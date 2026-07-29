<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalWrappingCssTest extends TestCase
{
    public function test_app_shell_prevents_logo_and_topbar_crush(): void
    {
        $css = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertIsString($css);

        $this->assertStringContainsString('#logoNavbar', $css);
        $this->assertStringContainsString('shell-logo-wordmark', $css);
        $this->assertStringContainsString('shell-logo-mark', $css);
        $this->assertStringContainsString('.top-navbar .mobile-left', $css);
        $this->assertStringContainsString('min-width: 0', $css);
        $this->assertStringContainsString('overflow-x: clip', $css);
        $this->assertStringContainsString('max-width: min(150px, 30vw)', $css);
        $this->assertStringContainsString('.balance-block .balance-label', $css);
        $this->assertStringNotContainsString('#sidebar.collapsed a { font-size: 0', $css);
    }

    public function test_interaction_css_includes_text_break_helpers(): void
    {
        $css = file_get_contents(public_path('assets/css/interaction.css'));
        $this->assertIsString($css);

        $this->assertStringContainsString('.slb-text-break', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('.catalog-site-url', $css);
        $this->assertStringContainsString('.blog-content a', $css);
        $this->assertStringContainsString('body.role-shell-marketing', $css);
    }

    public function test_admin_and_marketing_layouts_drop_font_size_zero_collapse(): void
    {
        $admin = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $marketing = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));

        $this->assertIsString($admin);
        $this->assertIsString($marketing);

        $this->assertStringNotContainsString('font-size: 0;', $admin);
        $this->assertStringNotContainsString('font-size: 0;', $marketing);
        $this->assertStringContainsString('mobile-sidebar-logo', $admin);
        $this->assertStringContainsString('mobile-sidebar-logo', $marketing);
        $this->assertStringContainsString('shell-logo-mark', $admin);
        $this->assertStringContainsString('shell-logo-mark', $marketing);
    }

    public function test_marketing_brand_line_scales_without_ellipsis_clip(): void
    {
        $blade = file_get_contents(resource_path('views/components/marketing-brand-line.blade.php'));
        $this->assertIsString($blade);
        $this->assertStringContainsString('clamp(1.05rem, 4.6vw, 1.85rem)', $blade);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $blade);
    }

    public function test_public_css_mirrors_match_assets_for_shell_and_interaction(): void
    {
        $this->assertFileEquals(
            public_path('assets/css/app-shell.css'),
            public_path('css/app-shell.css')
        );
        $this->assertFileEquals(
            public_path('assets/css/interaction.css'),
            public_path('css/interaction.css')
        );
        $this->assertFileEquals(
            public_path('assets/css/auth-pages.css'),
            public_path('css/auth-pages.css')
        );
    }
}
