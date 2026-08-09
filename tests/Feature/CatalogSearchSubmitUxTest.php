<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog search must not full-reload on every keystroke pause.
 * Enter / Apply submit once, with Searching… feedback and an in-flight guard.
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

    public function test_search_does_not_debounce_live_full_page_submit(): void
    {
        $js = $this->catalogJs();

        $this->assertStringNotContainsString('SEARCH_DEBOUNCE_MS', $js);
        $this->assertStringContainsString("e.key !== 'Enter'", $js);
        $this->assertStringContainsString("submitCatalogFilters({ reason: 'search' })", $js);
        $this->assertStringContainsString('catalogFilterSubmitInFlight', $js);
        $this->assertStringContainsString('if (catalogFilterSubmitInFlight) return;', $js);
        // Bulk rail may debounce client-side; main catalogSearchInput must not
        // live-submit the filter form on input.
        $this->assertDoesNotMatchRegularExpression(
            '/getElementById\(\'catalogSearchInput\'\)[\s\S]{0,400}addEventListener\(\'input\'[\s\S]{0,300}submitCatalogFilters/',
            $js
        );
    }

    public function test_search_busy_state_announces_searching(): void
    {
        $js = $this->catalogJs();
        $blade = $this->catalogBlade();

        $this->assertStringContainsString('Searching…', $js);
        $this->assertStringContainsString("reason === 'search' ? 'Searching…'", $js);
        $this->assertStringContainsString('catalog-results-busy__label', $js);
        $this->assertStringContainsString('id="catalogSearchStatus"', $blade);
        $this->assertStringContainsString('aria-live="polite"', $blade);
        $this->assertStringContainsString('Suggestions appear as you type', $blade);
        $this->assertStringContainsString('applyBtn.disabled = true', $js);
    }
}
