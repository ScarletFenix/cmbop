<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Phase 4 — mirrors CatalogCountryPicker group-browse helpers in catalog.js.
 *
 * Group click must not change country selection; closed-trigger may show a
 * non-removable DACH+ / Nordics label when picks sit inside that group.
 */
class CatalogCountryGroupExpandContractTest extends TestCase
{
    /**
     * @var array<string, list<string>>
     */
    private array $groups = [
        'dach_plus' => ['de', 'at', 'ch', 'lu', 'li'],
        'nordics' => ['se', 'no', 'dk', 'fi', 'is'],
    ];

    /**
     * @var array<string, string>
     */
    private array $labels = [
        'dach_plus' => 'DACH+',
        'nordics' => 'Nordics',
    ];

    /**
     * @param  list<string>  $values
     * @return array{key: string, label: string}|null
     */
    private function groupContextForValues(array $values, ?string $activeGroup = null): ?array
    {
        $selected = [];
        foreach ($values as $code) {
            $normalized = strtolower(trim((string) $code));
            if ($normalized !== '' && ! in_array($normalized, $selected, true)) {
                $selected[] = $normalized;
            }
        }

        if ($selected === []) {
            return null;
        }

        if ($activeGroup !== null) {
            $activeCodes = $this->groups[$activeGroup] ?? [];
            $allInActive = collect($selected)->every(fn (string $code) => in_array($code, $activeCodes, true));

            return $allInActive
                ? ['key' => $activeGroup, 'label' => $this->labels[$activeGroup]]
                : null;
        }

        if (count($selected) < 2) {
            return null;
        }

        $bestKey = null;
        $bestSize = PHP_INT_MAX;
        foreach ($this->groups as $key => $codes) {
            $covers = collect($selected)->every(fn (string $code) => in_array($code, $codes, true));
            if ($covers && count($codes) < $bestSize) {
                $bestKey = $key;
                $bestSize = count($codes);
            }
        }

        if ($bestKey === null) {
            return null;
        }

        return ['key' => $bestKey, 'label' => $this->labels[$bestKey]];
    }

    /**
     * @param  list<string>  $values
     */
    private function shouldCompactCountryDisplay(array $values): bool
    {
        if (count($values) <= 1) {
            return false;
        }

        return $this->groupContextForValues($values) === null;
    }

    public function test_group_click_does_not_imply_country_selection(): void
    {
        // Pure contract: browsing a group leaves the selection array untouched
        // until a country row is toggled (selectGroup never writes filters).
        $selected = [];
        $this->assertSame([], $selected);
        $this->assertNull($this->groupContextForValues($selected, 'dach_plus'));
    }

    public function test_de_plus_at_under_dach_shows_group_label_not_compact_chip(): void
    {
        $values = ['de', 'at'];

        $ctx = $this->groupContextForValues($values);
        $this->assertNotNull($ctx);
        $this->assertSame('dach_plus', $ctx['key']);
        $this->assertSame('DACH+', $ctx['label']);
        $this->assertFalse($this->shouldCompactCountryDisplay($values));

        // Active browse focus with a single member still earns the prefix.
        $one = $this->groupContextForValues(['de'], 'dach_plus');
        $this->assertSame('DACH+', $one['label'] ?? null);
    }

    public function test_lone_germany_without_browse_focus_has_no_group_prefix(): void
    {
        $this->assertNull($this->groupContextForValues(['de']));
        $this->assertNull($this->groupContextForValues(['de', 'us']));
        $this->assertTrue($this->shouldCompactCountryDisplay(['de', 'us']));
    }

    public function test_js_select_group_expands_without_bulk_check_or_filter_write(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function selectGroup(groupKey)', $js);
        $this->assertStringContainsString('Does not check any boxes', $js);
        $this->assertStringContainsString('setActiveGroup(groupKey)', $js);
        $this->assertStringContainsString('filterMultiOptions', $js);
        $this->assertStringContainsString('groupCodeSet', $js);
        $this->assertStringContainsString('selected-tag--group', $js);
        $this->assertStringContainsString('function groupContextForValues(', $js);
        $this->assertStringContainsString('function shouldCompactCountryDisplay(', $js);

        // No bulk-check / no writing selectedMultiFilters from selectGroup.
        $this->assertMatchesRegularExpression(
            '/function selectGroup\(groupKey\) \{([\s\S]*?)\n    function bindGroupActions/',
            $js
        );
        preg_match(
            '/function selectGroup\(groupKey\) \{([\s\S]*?)\n    function bindGroupActions/',
            $js,
            $selectGroupMatch
        );
        $selectGroupBody = $selectGroupMatch[1] ?? '';
        $this->assertStringContainsString('setActiveGroup(groupKey)', $selectGroupBody);
        $this->assertStringNotContainsString('input.checked = true', $selectGroupBody);
        $this->assertStringNotContainsString('updateMultiFilter(', $selectGroupBody);
        $this->assertStringNotContainsString('selectedMultiFilters.country', $selectGroupBody);

        // Closed-field path paints the group label then named × tags.
        $this->assertMatchesRegularExpression(
            '/function updateMultiDisplay\(type\)[\s\S]*?selected-tag--group[\s\S]*?renderNamedMultiTags\(/',
            $js
        );
    }
}
