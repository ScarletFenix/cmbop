<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\CartPricingService;
use App\Services\PlatformFeeService;
use Tests\TestCase;

class CartPricingServiceTest extends TestCase
{
    private CartPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new CartPricingService(new PlatformFeeService(PlatformFeeService::defaultTiers()));
    }

    public function test_advertiser_price_includes_tiered_platform_fee(): void
    {
        $site = $this->siteWithoutPromotions(100);

        $result = $this->pricing->priceForAdvertiser($site);

        // €100 → 13% fee → €113
        $this->assertSame(113.0, $result['base']);
        $this->assertSame(0.0, $result['additional']);
        $this->assertSame(113.0, $result['total']);
        $this->assertSame(100.0, $result['publisher_price']);
        $this->assertSame(13.0, $result['platform_fee_percent']);
        $this->assertSame(13.0, $result['platform_fee_amount']);
        $this->assertNull($result['sensitive_type']);
    }

    public function test_low_price_uses_fifteen_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(40);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(46.0, $result['base']);
        $this->assertSame(15.0, $result['platform_fee_percent']);
        $this->assertSame(40.0, $result['publisher_price']);
    }

    public function test_mid_high_price_uses_twelve_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(300);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(336.0, $result['base']);
        $this->assertSame(12.0, $result['platform_fee_percent']);
        $this->assertSame(300.0, $result['publisher_price']);
    }

    public function test_high_price_uses_ten_percent_fee(): void
    {
        $site = $this->siteWithoutPromotions(1000);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(1100.0, $result['base']);
        $this->assertSame(10.0, $result['platform_fee_percent']);
        $this->assertSame(1000.0, $result['publisher_price']);
    }

    public function test_sensitive_add_on_comes_from_site_config_not_client(): void
    {
        $site = $this->siteWithoutPromotions(100, ['casino' => 25]);

        $result = $this->pricing->priceForAdvertiser($site, 'casino');

        $this->assertSame(113.0, $result['base']);
        $this->assertSame(25.0, $result['additional']);
        $this->assertSame(138.0, $result['total']);
        $this->assertSame('casino', $result['sensitive_type']);
    }

    public function test_invalid_sensitive_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $site = $this->siteWithoutPromotions(100, ['casino' => 25]);

        $this->pricing->priceForAdvertiser($site, 'cbd');
    }

    public function test_sensitive_type_matching_is_case_insensitive(): void
    {
        $site = $this->siteWithoutPromotions(100, ['CBD' => 40]);

        $result = $this->pricing->priceForAdvertiser($site, 'cbd');

        $this->assertSame(40.0, $result['additional']);
        $this->assertSame(153.0, $result['total']);
        $this->assertSame('CBD', $result['sensitive_type']);
    }

    public function test_custom_discount_is_floored_at_publisher_payout(): void
    {
        $site = $this->siteWithCustomDiscount(100, 20, ['crypto' => 25]);

        $withAddon = $this->pricing->priceForAdvertiser($site, 'crypto');
        // list = 113 + 25 = 138; 20% off => 110.4, but publisher payout is 125 → floor
        $this->assertSame(138.0, $withAddon['list_total']);
        $this->assertSame(20.0, $withAddon['discount_percent_nominal']);
        // Effective % after floor: €13 / €138 ≈ 9.42% — never label as −20%.
        $this->assertSame(9.42, $withAddon['discount_percent']);
        $this->assertSame(125.0, $withAddon['total']);
        $this->assertSame(13.0, $withAddon['discount_amount']); // 138 - 125
        $this->assertSame(100.0, $withAddon['publisher_price']);
        $this->assertEquals(0.0, $withAddon['platform_fee_amount']);
        $this->assertStringContainsString('Site offer −9.42%', $withAddon['discount_labels'][0] ?? '');

        $baseOnly = $this->pricing->priceForAdvertiser($site, null);
        // list = 113; 20% off => 90.4, floored at publisher 100
        $this->assertSame(113.0, $baseOnly['list_total']);
        $this->assertSame(100.0, $baseOnly['total']);
        $this->assertSame(13.0, $baseOnly['discount_amount']);
        $this->assertSame(20.0, $baseOnly['discount_percent_nominal']);
        $this->assertSame(11.5, $baseOnly['discount_percent']); // 13/113
        $this->assertEquals(0.0, $baseOnly['platform_fee_amount']);
        $this->assertGreaterThanOrEqual(
            $baseOnly['publisher_price'] + $baseOnly['additional'],
            $baseOnly['total']
        ); // total >= publisher payout (PHPUnit: actual >= expected)
    }

    public function test_custom_vs_bulk_is_exclusive_better_of_not_stacked(): void
    {
        $customWins = $this->siteWithCustomAndBulk(100, custom: 20, bulk: 15);
        $atPack = $this->pricing->priceForAdvertiser($customWins, null, 3);
        // Custom 20% beats bulk 15%; both floor at €100 so effective is fee-only.
        $this->assertSame(20.0, $atPack['discount_percent_nominal']);
        $this->assertSame(100.0, $atPack['total']);
        $this->assertSame(11.5, $atPack['discount_percent']);
        $this->assertStringContainsString('Site offer', $atPack['discount_labels'][0] ?? '');
        $this->assertStringNotContainsString('Bulk deal', implode(' ', $atPack['discount_labels']));

        $bulkWins = $this->siteWithCustomAndBulk(100, custom: 10, bulk: 15);
        $bulkPack = $this->pricing->priceForAdvertiser($bulkWins, null, 3);
        $this->assertSame(15.0, $bulkPack['discount_percent_nominal']);
        $this->assertSame(100.0, $bulkPack['total']); // 15% of 113 floors at 100
        $this->assertSame(11.5, $bulkPack['discount_percent']);
        $this->assertStringContainsString('Bulk deal', $bulkPack['discount_labels'][0] ?? '');

        // Qty below pack: only custom applies (bulk inactive).
        $single = $this->pricing->priceForAdvertiser($bulkWins, null, 1);
        $this->assertSame(10.0, $single['discount_percent_nominal']);
        $this->assertSame(101.7, $single['total']);
        $this->assertSame(10.0, $single['discount_percent']);
        $this->assertStringContainsString('Site offer', $single['discount_labels'][0] ?? '');
    }

    public function test_modest_discount_reduces_fee_but_keeps_publisher_whole(): void
    {
        // Publisher list €100 + 10% sale. Advertiser sees €113 then €101.70.
        $site = $this->siteWithCustomDiscount(100, 10);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertSame(113.0, $result['list_total']);
        $this->assertSame(101.7, $result['total']);
        $this->assertSame(10.0, $result['discount_percent']);
        $this->assertSame(10.0, $result['discount_percent_nominal']);
        $this->assertSame(11.3, $result['discount_amount']);
        $this->assertSame(100.0, $result['publisher_price']);
        $this->assertSame(1.7, $result['platform_fee_amount']);
        $this->assertGreaterThanOrEqual(
            $result['publisher_price'] + $result['additional'],
            $result['total']
        );
    }

    public function test_fifteen_percent_on_three_oh_four_floors_advertiser_at_publisher_list(): void
    {
        // Publisher My Sites shows €304 → €258.40. Advertiser still pays €304 (option A).
        $site = $this->siteWithCustomDiscount(304, 15);

        $result = $this->pricing->priceForAdvertiser($site);

        $this->assertEqualsWithDelta(340.48, $result['list_total'], 0.001);
        $this->assertSame(304.0, $result['total']);
        $this->assertSame(15.0, $result['discount_percent_nominal']);
        $this->assertSame(10.71, $result['discount_percent']);
        $this->assertSame(304.0, $result['publisher_price']);
        $this->assertEquals(0.0, $result['platform_fee_amount']);
    }

    /**
     * @param  array<string, float|int>|null  $sensitive
     */
    private function siteWithoutPromotions(float $price, ?array $sensitive = null): Site
    {
        $site = new class extends Site
        {
            public function activeCustomDiscountPercent(): ?float
            {
                return null;
            }

            public function joinsBulkDiscount(): bool
            {
                return false;
            }
        };

        $site->forceFill([
            'site_name' => 'Example',
            'price' => $price,
            'sensitive_prices' => $sensitive,
        ]);

        return $site;
    }

    /**
     * @param  array<string, float|int>|null  $sensitive
     */
    private function siteWithCustomDiscount(float $price, float $percent, ?array $sensitive = null): Site
    {
        $site = new class extends Site
        {
            public ?float $testDiscountPercent = null;

            public function activeCustomDiscountPercent(): ?float
            {
                return $this->testDiscountPercent;
            }

            public function joinsBulkDiscount(): bool
            {
                return false;
            }
        };

        $site->testDiscountPercent = $percent;
        $site->forceFill([
            'site_name' => 'Example',
            'price' => $price,
            'sensitive_prices' => $sensitive,
        ]);

        return $site;
    }

    private function siteWithCustomAndBulk(float $price, float $custom, float $bulk): Site
    {
        $site = new class extends Site
        {
            public ?float $testDiscountPercent = null;

            public function activeCustomDiscountPercent(): ?float
            {
                return $this->testDiscountPercent;
            }

            public function joinsBulkDiscount(): bool
            {
                return $this->bulk_discount_percent !== null
                    && (float) $this->bulk_discount_percent > 0;
            }
        };

        $site->testDiscountPercent = $custom;
        $site->forceFill([
            'site_name' => 'Example',
            'price' => $price,
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => $bulk,
        ]);

        return $site;
    }

    public function test_homepage_fee_added_after_discount_undiscounted(): void
    {
        $site = $this->siteWithHomepageOffers(100, [1 => 0.0, 7 => 25.0, 30 => 0.0], 20.0);

        $result = $this->pricing->priceForAdvertiser($site, null, 1, 7, false);

        // €100 → €113 list; 20% off → €90.40 article (above €100 publisher floor → €100)
        $this->assertSame(7, $result['homepage_days']);
        $this->assertSame(25.0, $result['homepage_price']);
        $this->assertSame(100.0, $result['article_total']); // floored at publisher payout
        $this->assertSame(125.0, $result['total']);
    }

    public function test_default_homepage_picks_longest_free(): void
    {
        $site = $this->siteWithHomepageOffers(100, [1 => 0.0, 7 => 25.0, 30 => 0.0]);

        $result = $this->pricing->priceForAdvertiser($site, null, 1, null, true);

        $this->assertSame(30, $result['homepage_days']);
        $this->assertSame(0.0, $result['homepage_price']);
        $this->assertSame(113.0, $result['total']);
    }

    public function test_paid_only_homepage_defaults_to_none(): void
    {
        $site = $this->siteWithHomepageOffers(100, [7 => 25.0, 30 => 60.0]);

        $result = $this->pricing->priceForAdvertiser($site, null, 1, null, true);

        $this->assertNull($result['homepage_days']);
        $this->assertSame(0.0, $result['homepage_price']);
        $this->assertSame(113.0, $result['total']);
    }

    /**
     * @param  array<int, float>  $homepage
     */
    private function siteWithHomepageOffers(float $price, array $homepage, ?float $customDiscount = null): Site
    {
        $site = new class extends Site
        {
            /** @var array<int, float> */
            public array $testHomepage = [];

            public ?float $testDiscountPercent = null;

            public function activeCustomDiscountPercent(): ?float
            {
                return $this->testDiscountPercent;
            }

            public function joinsBulkDiscount(): bool
            {
                return false;
            }

            public function homepagePlacementOptions(): array
            {
                return $this->testHomepage;
            }

            public function longestFreeHomepageDays(): ?int
            {
                $free = [];
                foreach ($this->testHomepage as $days => $fee) {
                    if ((float) $fee <= 0) {
                        $free[] = (int) $days;
                    }
                }

                return $free === [] ? null : max($free);
            }

            public function enabledSocialChannels(): array
            {
                return ['facebook'];
            }
        };

        $site->testHomepage = $homepage;
        $site->testDiscountPercent = $customDiscount;
        $site->forceFill([
            'site_name' => 'Example',
            'price' => $price,
        ]);

        return $site;
    }
}
