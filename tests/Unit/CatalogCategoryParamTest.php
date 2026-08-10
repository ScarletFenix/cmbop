<?php

namespace Tests\Unit;

use App\Models\Category;
use Database\Seeders\CategoriesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0 contracts for catalog category= wire format.
 *
 * Wire: `|` between niches (publisher-aligned). Country/language stay `,`.
 * Legacy comma URLs: never explode(',') blindly — longest-first against known names.
 */
class CatalogCategoryParamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();
    }

    public function test_inventory_lists_every_comma_containing_niche_from_db(): void
    {
        $fromDb = Category::query()
            ->where('name', 'like', '%,%')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame(
            Category::NICHES_CONTAINING_COMMA,
            $fromDb,
            'Fixture constant must stay in sync with categories that contain commas.'
        );

        foreach (Category::NICHES_CONTAINING_COMMA as $niche) {
            $this->assertStringContainsString(',', $niche);
        }
    }

    public function test_pipe_is_the_catalog_wire_separator(): void
    {
        $encoded = Category::encodeCatalogCategoryParam([
            'Health & Wellness',
            'Marketing, PR & Advertising',
        ]);

        $this->assertSame(
            'Health & Wellness|Marketing, PR & Advertising',
            $encoded
        );
        $this->assertStringNotContainsString(
            'Health & Wellness,Marketing',
            $encoded
        );
    }

    public function test_canonicalize_rewrites_legacy_comma_url_to_pipe(): void
    {
        $this->assertSame(
            'Health & Wellness|Marketing, PR & Advertising',
            Category::canonicalizeCatalogCategoryParam(
                'Health & Wellness,Marketing, PR & Advertising'
            )
        );
    }

    public function test_canonicalize_keeps_pipe_multi_and_canonical_names(): void
    {
        $this->assertSame(
            'Health & Wellness|Marketing, PR & Advertising',
            Category::canonicalizeCatalogCategoryParam(
                'Health & Wellness|Marketing, PR & Advertising'
            )
        );
    }

    public function test_canonicalize_preserves_unknown_tokens(): void
    {
        $this->assertSame(
            'marketing',
            Category::canonicalizeCatalogCategoryParam('marketing')
        );
    }

    public function test_comma_niche_alone_is_one_token_not_split(): void
    {
        $tokens = Category::parseCatalogCategoryParam('Marketing, PR & Advertising');

        $this->assertSame(['Marketing, PR & Advertising'], $tokens);
        $this->assertNotContains('Marketing', $tokens);
        $this->assertNotContains('PR & Advertising', $tokens);
    }

    public function test_pipe_multi_keeps_comma_niche_intact(): void
    {
        $tokens = Category::parseCatalogCategoryParam(
            'Health & Wellness|Marketing, PR & Advertising'
        );

        $this->assertSame(
            ['Health & Wellness', 'Marketing, PR & Advertising'],
            $tokens
        );
    }

    public function test_legacy_comma_url_with_known_niches_resolves_longest_first(): void
    {
        $tokens = Category::parseCatalogCategoryParam(
            'Health & Wellness,Marketing, PR & Advertising'
        );

        $this->assertSame(
            ['Health & Wellness', 'Marketing, PR & Advertising'],
            $tokens
        );
    }

    public function test_legacy_two_simple_niches_still_split_on_comma(): void
    {
        $tokens = Category::parseCatalogCategoryParam(
            'Health & Wellness,Technology & Gadgets'
        );

        $this->assertSame(
            ['Health & Wellness', 'Technology & Gadgets'],
            $tokens
        );
    }

    public function test_resolve_maps_parsed_tokens_to_canonical_names(): void
    {
        $resolved = Category::resolveNicheNames(
            Category::parseCatalogCategoryParam('Marketing, PR & Advertising')
        )['resolved'];

        $this->assertSame(['Marketing, PR & Advertising'], $resolved);
    }

    public function test_catalog_filter_labels_keep_unknown_niches(): void
    {
        $labels = Category::catalogFilterNicheNames('Crypto & Web3');

        $this->assertSame(['Crypto & Web3'], $labels);
    }

    public function test_catalog_filter_labels_merge_canonical_and_unknown(): void
    {
        $labels = Category::catalogFilterNicheNames(
            'Marketing, PR & Advertising|Crypto & Web3'
        );

        $this->assertSame(
            ['Marketing, PR & Advertising', 'Crypto & Web3'],
            $labels
        );
    }

    public function test_catalog_filter_labels_keep_raw_alias_and_canonical(): void
    {
        // Group alias Technology → Technology & Gadgets, but legacy sites may
        // still store "Technology" — filter must OR both.
        $labels = Category::catalogFilterNicheNames('Technology');

        $this->assertSame(
            ['Technology & Gadgets', 'Technology'],
            $labels
        );
    }

    public function test_catalog_filter_labels_reverse_expand_after_canonicalize(): void
    {
        // Form / live path rewrites Technology → Technology & Gadgets; filter
        // must still OR the legacy group alias.
        $canonical = Category::canonicalizeCatalogCategoryParam('Technology');
        $this->assertSame('Technology & Gadgets', $canonical);

        $labels = Category::catalogFilterNicheNames($canonical);
        $this->assertContains('Technology & Gadgets', $labels);
        $this->assertContains('Technology', $labels);
    }

    public function test_resolve_does_not_prefix_map_free_text_to_unrelated_niche(): void
    {
        // "Crypto" is not a group alias; must not become "Crypto & Blockchain".
        $resolved = Category::resolveNicheNames(['Crypto']);

        $this->assertSame([], $resolved['resolved']);
        $this->assertSame(['Crypto'], $resolved['unknown']);
        $this->assertSame(['Crypto'], Category::catalogFilterNicheNames('Crypto'));
    }

    public function test_comma_niches_survive_empty_known_names_list(): void
    {
        $this->assertSame(
            ['Marketing, PR & Advertising'],
            Category::parseCatalogCategoryParam('Marketing, PR & Advertising', [])
        );
        $this->assertSame(
            ['Events, Conferences & Trade Fairs'],
            Category::parseCatalogCategoryParam('Events, Conferences & Trade Fairs', [])
        );
    }

    public function test_display_labels_keep_comma_niche_as_one_badge(): void
    {
        $labels = Category::displayNicheLabels(
            ['Marketing, PR & Advertising', 'Health & Wellness'],
            null
        );

        $this->assertSame(
            ['Marketing, PR & Advertising', 'Health & Wellness'],
            $labels
        );
    }

    public function test_display_labels_do_not_explode_legacy_comma_niche_string(): void
    {
        $labels = Category::displayNicheLabels(
            null,
            'Marketing, PR & Advertising'
        );

        $this->assertSame(['Marketing, PR & Advertising'], $labels);
    }
}
