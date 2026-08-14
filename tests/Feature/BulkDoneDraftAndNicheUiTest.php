<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkDoneDraftAndNicheUiTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_done_page_wires_draft_storage_and_niche_dropdown_hooks(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://draft-ui.example',
            'domain' => 'draft-ui.example',
            'price' => 55,
        ]);

        $category = Category::query()->first();
        $this->assertNotNull($category);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('id="bulkDoneForm"', false)
            ->assertSee('bulk-done-panel', false)
            ->assertSee('bulk-done-table-wrap', false)
            ->assertSee('data-bulk-done-row', false)
            ->assertSee('bulkDoneDraft:'.$bulk->id.':'.$this->marketer->id, false)
            ->assertSee('sessionStorage', false)
            ->assertSee('restoreDraftIfNeeded', false)
            ->assertSee('categoryWrapper-done'.$bulk->items()->first()->id, false)
            ->getContent();

        $this->assertStringContainsString('Select niches', $html);
        $this->assertStringContainsString('bulk-done-niches-cell', $html);
        $this->assertStringContainsString('bulk-done-row__summary', $html);
        $this->assertStringContainsString('bulk-done-row__body', $html);
        $this->assertStringContainsString('data-bulk-clear-row', $html);
        $this->assertStringContainsString('data-bulk-copy-above', $html);
        $this->assertStringContainsString('data-bulk-done-chip', $html);
        $this->assertStringContainsString('function expandBulkDoneRow', $html);
        $this->assertStringContainsString('function clearBulkDoneRow', $html);
        $this->assertStringContainsString('function copyBulkDoneRowFromAbove', $html);
        $this->assertStringContainsString('function updateBulkDoneChip', $html);
        $this->assertStringContainsString('row.open = true', $html);
        $this->assertStringContainsString('[data-bulk-clear-row]', $html);
        $this->assertStringContainsString('[data-bulk-copy-above]', $html);
        $this->assertStringContainsString('No categories found', $html);
        $this->assertStringContainsString('Type to search niches', $html);
        $this->assertStringContainsString("emptyId: 'categoryEmpty-", $html);
        $this->assertStringNotContainsString('table-responsive mb-3', $html);

        $this->assertStringContainsString('staff-sites.css', $html);
        $this->assertStringContainsString('bulk-done-list', $html);
        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.bulk-done-panel', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__fields', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-empty', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-partial', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-ready', $staffCss);
        $this->assertStringNotContainsString('table-layout: fixed', $staffCss);

        $js = file_get_contents(public_path('js/multi-select.js'));
        $this->assertStringContainsString('multi-select-dropdown--fixed', $js);
        $this->assertStringContainsString('position: \'fixed\'', $js);
        $this->assertStringContainsString('getBoundingClientRect', $js);
        // Selected niches must wrap inside .multi-select-tags (not as loose flex children).
        $this->assertStringContainsString('class="multi-select-tags"', $js);
        // Niche picks must fire a native bubbling change so bulk Done draft autosave hears them.
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $js);
        // Catalog-parity keyboard: Enter adds sole/focused match; Backspace peels last chip.
        $this->assertStringContainsString('selectSoleOrFocused', $js);
        $this->assertStringContainsString("e.key === 'Enter'", $js);
        $this->assertStringContainsString("e.key === 'Backspace'", $js);
        $this->assertStringContainsString('removeLast', $js);
        $this->assertStringContainsString('categories: categories ? categories.value : \'\'', $html);
        $this->assertStringContainsString('multiSelects[itemId].setSelectedItems(nicheValues, nicheValues)', $html);
        $this->assertStringContainsString('Category::catalogPickerNames()', file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php')));

        $css = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringContainsString('max-height: 4.75rem', $css);
        $this->assertStringContainsString('.multi-select-empty', $css);
        $cssDup = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $cssDup);
        $this->assertStringContainsString('max-height: 4.75rem', $cssDup);
    }

    public function test_marketing_layout_sidebar_collapse_uses_shell_tokens(): void
    {
        $layout = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));
        $this->assertStringContainsString('marketing-shell.css', $layout);
        $this->assertStringContainsString('syncSidebarForViewport', $layout);
        $this->assertStringContainsString('isDesktopNav', $layout);
        $this->assertStringContainsString('class="nav-label"', $layout);
        $this->assertStringNotContainsString('<style>', $layout);
        $this->assertStringNotContainsString('transition: all 0.3s ease-in-out', $layout);

        $shell = file_get_contents(public_path('assets/css/marketing-shell.css'));
        $this->assertStringContainsString('--shell-sidebar-width: 230px', $shell);
        $this->assertStringContainsString('--shell-sidebar-collapsed: 70px', $shell);

        $appShell = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('max-width: var(--shell-sidebar-collapsed)', $appShell);
    }

    public function test_done_row_error_prefix_does_not_open_sibling_item_ids(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
        ]);

        $this->insertDoneItem($bulk->id, 1, 'https://first.example', 'first.example', 40);
        $this->insertDoneItem($bulk->id, 2, 'https://prefix.example', 'prefix.example', 50);
        $this->insertDoneItem($bulk->id, 21, 'https://long.example', 'long.example', 60);

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    21 => [
                        'da' => 10,
                    ],
                ],
            ])
            ->assertOk()
            ->assertSee('Finish the boxes first.', false)
            ->getContent();

        $this->assertFalse(
            $this->doneRowIsOpen($html, 'prefix.example'),
            'items.21.country must not mark item 2 as having errors'
        );
        $this->assertTrue(
            $this->doneRowIsOpen($html, 'long.example'),
            'The row that actually has errors should stay expanded'
        );

        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString('$itemErrorPrefix = \'items.\'.$item->id.\'.\'', $blade);
        $this->assertStringNotContainsString(
            "str_starts_with((string) \$key, 'items.'.\$item->id)",
            $blade
        );
    }

    private function insertDoneItem(int $bulkId, int $id, string $url, string $domain, float $price): void
    {
        $item = new BulkSiteRequestItem([
            'bulk_site_request_id' => $bulkId,
            'site_url' => $url,
            'domain' => $domain,
            'price' => $price,
        ]);
        $item->id = $id;
        $item->save();
    }

    private function doneRowIsOpen(string $html, string $domain): bool
    {
        preg_match_all(
            '/<details class="bulk-done-row" data-bulk-done-row([^>]*)>(.*?)<\/details>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            if (! str_contains($match[2], $domain)) {
                continue;
            }

            return (bool) preg_match('/\sopen(?:\s|>|$)/', $match[1]);
        }

        $this->fail('Done row for '.$domain.' was not rendered');
    }
}
