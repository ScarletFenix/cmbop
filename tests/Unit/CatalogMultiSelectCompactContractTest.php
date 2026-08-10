<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Phase 7 — mirrors pure helpers in public/assets/js/catalog.js
 * (formatMultiSelectTrigger / shouldCompactMultiDisplay).
 */
class CatalogMultiSelectCompactContractTest extends TestCase
{
    /**
     * Mirrors shouldCompactMultiDisplay(values) in catalog.js.
     *
     * @param  list<string>  $values
     */
    private function shouldCompact(array $values): bool
    {
        return count($values) > 1;
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

    public function test_v1_compact_rule_one_tag_vs_count_chip(): void
    {
        $this->assertFalse($this->shouldCompact([]));
        $this->assertFalse($this->shouldCompact(['de']));
        $this->assertTrue($this->shouldCompact(['de', 'at']));
        $this->assertTrue($this->shouldCompact(['de', 'at', 'ch']));
    }

    public function test_format_trigger_pluralizes_from_markup_hints(): void
    {
        $this->assertSame('', $this->formatTrigger(0, 'country', 'countries'));
        $this->assertSame('1 country', $this->formatTrigger(1, 'country', 'countries'));
        $this->assertSame('3 countries', $this->formatTrigger(3, 'country', 'countries'));
        $this->assertSame('2 categories', $this->formatTrigger(2, 'category', 'categories'));
        $this->assertSame('1 language', $this->formatTrigger(1, 'language', 'languages'));
    }

    public function test_js_exposes_matching_helper_names(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function formatMultiSelectTrigger(count, singular, plural)', $js);
        $this->assertStringContainsString('function shouldCompactMultiDisplay(values)', $js);
        $this->assertStringContainsString('window.CatalogMultiSelectFormat', $js);
        $this->assertStringContainsString('values.length > 1', $js);
    }
}
