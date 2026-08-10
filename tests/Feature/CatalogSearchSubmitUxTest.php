<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog search: typing updates live result rows; Enter / Apply push history
 * with Searching… feedback and an in-flight guard.
 */
class CatalogSearchSubmitUxTest extends TestCase
{
    use RefreshDatabase;

    private function catalogJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/catalog.js'));
    }

    private function catalogBlade(): string
    {
        return (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));
    }

    public function test_search_typing_schedules_live_rows_not_full_page_debounce(): void
    {
        $js = $this->catalogJs();

        $this->assertStringNotContainsString('SEARCH_DEBOUNCE_MS', $js);
        $this->assertStringContainsString('initCatalogSearchLiveRows', $js);
        $this->assertStringContainsString('CATALOG_SEARCH_MIN_CHARS', $js);
        $this->assertStringContainsString('scheduleCatalogFilterLive({ replace: true, intent: \'search\' })', $js);
        $this->assertStringContainsString("e.key !== 'Enter'", $js);
        $this->assertStringContainsString("submitCatalogFilters({ replace: false, intent: 'search', reason: 'search' })", $js);
        // Typing uses the live /results path (scheduleCatalogFilterLive), not a
        // separate full-page SEARCH_DEBOUNCE navigation.
        $this->assertMatchesRegularExpression(
            '/function initCatalogSearchLiveRows\([\s\S]*?addEventListener\(\'input\'[\s\S]*?scheduleLiveSearch/',
            $js
        );
    }

    public function test_search_busy_state_announces_searching(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        $this->assertStringContainsString('Searching…', $js);
        $this->assertStringContainsString("if (intent === 'search') return 'Searching…';", $js);
        $this->assertStringContainsString('catalog-results-busy__label', $js);
        $this->assertStringContainsString('id="catalogSearchStatus"', $blade);
        $this->assertStringContainsString('aria-live="polite"', $blade);
        $this->assertStringContainsString('Results update as you type', $blade);
        $this->assertStringContainsString('applyBtn.disabled = true', $js);
    }
}
