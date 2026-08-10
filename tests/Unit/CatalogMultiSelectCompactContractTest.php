<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Contract for multi-select display helpers in public/assets/js/catalog.js.
 * Phase 0/1 — always named tags; compact count chip is retired (returns false).
 */
class CatalogMultiSelectCompactContractTest extends TestCase
{
    /**
     * Mirrors shouldCompactMultiDisplay(values) in catalog.js (always false).
     *
     * @param  list<string>  $values
     */
    private function shouldCompact(array $values): bool
    {
        return false;
    }

    /**
     * Mirrors formatMultiSelectTrigger(count, singular, plural) in catalog.js.
     */
    private function formatTrigger(int $count, string $singular, string $plural): string
    {
        if ($count < 1) {
            return '';
        }

        return $count.' '.($count === 1 ? $singular : $plural);
    }

    public function test_named_tags_never_compact_for_any_selection_count(): void
    {
        $this->assertFalse($this->shouldCompact([]));
        $this->assertFalse($this->shouldCompact(['de']));
        $this->assertFalse($this->shouldCompact(['de', 'at']));
        $this->assertFalse($this->shouldCompact(['de', 'at', 'ch']));
    }

    public function test_format_trigger_pluralizes_from_markup_hints(): void
    {
        $this->assertSame('', $this->formatTrigger(0, 'country', 'countries'));
        $this->assertSame('1 country', $this->formatTrigger(1, 'country', 'countries'));
        $this->assertSame('3 countries', $this->formatTrigger(3, 'country', 'countries'));
        $this->assertSame('2 categories', $this->formatTrigger(2, 'category', 'categories'));
        $this->assertSame('1 language', $this->formatTrigger(1, 'language', 'languages'));
    }

    public function test_js_exposes_named_tag_policy(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function formatMultiSelectTrigger(count, singular, plural)', $js);
        $this->assertStringContainsString('function shouldCompactMultiDisplay(values)', $js);
        $this->assertStringContainsString('window.CatalogMultiSelectFormat', $js);
        $this->assertMatchesRegularExpression(
            '/function shouldCompactMultiDisplay\(values\)\s*\{\s*return false;/s',
            $js
        );
        $this->assertStringContainsString('always named tags', $js);
        $this->assertStringContainsString('multiFilterOptionLabel', $js);
    }
}
