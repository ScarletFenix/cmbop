<?php

namespace Tests\Feature;

use App\Support\MarketingCssBundle;
use Tests\TestCase;

class MarketingCssBundleTest extends TestCase
{
    public function test_public_pages_load_one_marketing_stylesheet(): void
    {
        MarketingCssBundle::write();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'assets/css/marketing-bundle.css'));
        $this->assertStringNotContainsString('assets/css/type-system.css', $html);
        $this->assertStringNotContainsString('assets/css/hover-system.css?v=', $html);
        $this->assertStringNotContainsString('assets/css/slb-live-search.css?v=', $html);
    }

    public function test_bundle_contains_source_tokens_and_keeps_hover_last(): void
    {
        $css = MarketingCssBundle::write();

        $this->assertStringContainsString('--font-sans', $css);
        $this->assertStringContainsString('--hover-1', $css);
        $this->assertStringContainsString('.slb-search-wrap', $css);
        $this->assertStringNotContainsString('/*', $css);
        $files = MarketingCssBundle::FILES;
        $this->assertSame('hover-system.css', $files[array_key_last($files)]);

        $hover = MarketingCssBundle::minify((string) file_get_contents(public_path('assets/css/hover-system.css')));
        $this->assertTrue(str_ends_with($css, $hover), 'hover-system.css must remain last in the bundle cascade');
    }

    public function test_command_writes_the_public_bundle(): void
    {
        $path = MarketingCssBundle::absolutePath();
        if (is_file($path)) {
            unlink($path);
        }

        $this->artisan('css:bundle-marketing')->assertSuccessful();
        $this->assertFileExists($path);
        $this->assertGreaterThan(1000, filesize($path));
    }

    public function test_url_if_ready_returns_the_bundle_when_present(): void
    {
        $this->assertNotNull(MarketingCssBundle::urlIfReady());
    }

    public function test_public_layout_keeps_source_sheets_as_leftover_fallback(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("method_exists(\\App\\Support\\MarketingCssBundle::class, 'urlIfReady')", $layout);
        foreach (MarketingCssBundle::FILES as $file) {
            $this->assertStringContainsString('assets/css/'.$file, $layout);
        }

        $hover = 'assets/css/hover-system.css';
        $type = 'assets/css/type-system.css';
        $this->assertGreaterThan(
            strrpos($layout, $type),
            strrpos($layout, $hover),
            'hover-system.css must remain last in the leftover source-sheet fallback'
        );
    }
}
