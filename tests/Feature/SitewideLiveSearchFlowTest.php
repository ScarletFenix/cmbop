<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Catalog main-search contract is shared sitewide via SlbLiveSearch.
 */
class SitewideLiveSearchFlowTest extends TestCase
{
    private function liveSearchJs(): string
    {
        return (string) file_get_contents(public_path('js/slb-live-search.js'));
    }

    public function test_shared_helper_matches_catalog_main_search_contract(): void
    {
        $js = $this->liveSearchJs();

        $this->assertStringContainsString('DEBOUNCE_MS = 350', $js);
        $this->assertStringContainsString('MIN_CHARS = 2', $js);
        $this->assertStringContainsString("e.key !== 'Enter'", $js);
        $this->assertStringContainsString("historyMode: 'replace'", $js);
        $this->assertStringContainsString("historyMode: 'push'", $js);
        $this->assertStringContainsString("reason: 'clear'", $js);
        $this->assertStringContainsString('Type at least 2 characters to search', $js);
        $this->assertStringContainsString('slb:livesearch', $js);
        $this->assertStringContainsString("reason === 'enter' || reason === 'clear'", $js);
    }

    public function test_every_layout_loads_the_shared_live_search_assets(): void
    {
        $layouts = [
            'advertiser/layouts/app.blade.php',
            'publisher/layouts/app.blade.php',
            'admin/layouts/app.blade.php',
            'marketing/layouts/app.blade.php',
            'layouts/app.blade.php',
        ];

        foreach ($layouts as $layout) {
            $markup = (string) file_get_contents(resource_path('views/'.$layout));
            $this->assertStringContainsString('js/slb-live-search.js', $markup, $layout);
            $this->assertStringContainsString('assets/css/slb-live-search.css', $markup, $layout);

            preg_match_all('/<link[^>]+assets\/css\/([a-z-]+)\.css/', $markup, $matches);
            // hover-system must stay last in the assets/css cascade.
            $this->assertContains('hover-system', $matches[1], $layout);
            $this->assertContains('slb-live-search', $matches[1], $layout);
            $this->assertSame('hover-system', end($matches[1]), $layout.' must keep hover-system last among assets/css');
        }
    }

    public function test_catalog_and_orders_delegate_to_shared_helper(): void
    {
        $catalog = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $orders = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));

        $this->assertStringContainsString('SlbLiveSearch.init', $catalog);
        $this->assertStringContainsString('scheduleLiveSearch', $catalog);
        $this->assertStringContainsString('SlbLiveSearch.init', $orders);
        $this->assertStringContainsString('scheduleOrdersLiveSearch', $orders);
    }

    public function test_high_traffic_search_bars_use_catalog_parity_flow(): void
    {
        $paths = [
            resource_path('views/publisher/tasks.blade.php'),
            resource_path('views/admin/orders/index.blade.php'),
            resource_path('views/admin/payments.blade.php'),
            resource_path('views/admin/withdrawals.blade.php'),
            public_path('assets/js/publisher-websites.js'),
        ];

        foreach ($paths as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('SlbLiveSearch', $body, basename($path));
        }

        $forms = [
            resource_path('views/notifications/all.blade.php'),
            resource_path('views/advertiser/billing/index.blade.php'),
            resource_path('views/admin/deposits.blade.php'),
            resource_path('views/marketing/history.blade.php'),
        ];

        foreach ($forms as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('data-slb-live-search="form"', $body, basename($path));
        }

        $library = (string) file_get_contents(resource_path('views/advertiser/content-library.blade.php'));
        $this->assertStringNotContainsString('data-slb-live-search="form"', $library);
        $this->assertStringContainsString('for="librarySearchInput">Search</label>', $library);
        $this->assertStringContainsString('id="librarySearchClear"', $library);
        $this->assertStringContainsString('id="librarySearchStatus"', $library);
        $this->assertStringContainsString('libraryResultsUrl', $library);

        $libraryJs = (string) file_get_contents(public_path('assets/js/content-library.js'));
        $this->assertStringContainsString('SlbLiveSearch.init', $libraryJs);
        $this->assertStringContainsString('fetchLibraryResults', $libraryJs);
    }
}
