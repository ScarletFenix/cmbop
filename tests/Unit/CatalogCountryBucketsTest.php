<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogCountryBuckets;
use Tests\TestCase;

class CatalogCountryBucketsTest extends TestCase
{
    private CatalogCountryBuckets $buckets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buckets = new CatalogCountryBuckets;
    }

    public function test_order_buckets_follow_confirmed_priority(): void
    {
        $order = $this->buckets->orderBuckets();

        $this->assertSame(
            ['de', 'fr', 'it', 'es', 'uk', 'nl', 'pl'],
            $order['big_europe']
        );
        $this->assertSame(
            ['se', 'no', 'dk', 'fi', 'is'],
            $order['nordics']
        );
        $this->assertSame(['us', 'ca', 'au'], $order['big_english']);
        $this->assertSame(['nz', 'za', 'sg'], $order['other_english']);
        $this->assertContains('mx', $order['other_language_markets']);
        $this->assertContains('cn', $order['other_language_markets']);
        $this->assertContains('ae', $order['other_language_markets']);
    }

    public function test_uk_is_only_in_big_europe(): void
    {
        $this->assertSame('big_europe', $this->buckets->bucketFor('uk'));

        foreach ($this->buckets->orderBuckets() as $key => $codes) {
            if ($key === 'big_europe') {
                $this->assertContains('uk', $codes);

                continue;
            }
            $this->assertNotContains('uk', $codes, "uk must not appear in {$key}");
        }
    }

    public function test_ie_is_in_small_europe_not_english_buckets(): void
    {
        $this->assertSame('small_europe', $this->buckets->bucketFor('ie'));
        $this->assertContains('ie', $this->buckets->orderBuckets()['small_europe']);
        $this->assertNotContains('ie', $this->buckets->orderBuckets()['big_english']);
        $this->assertNotContains('ie', $this->buckets->orderBuckets()['other_english']);
    }

    public function test_liechtenstein_is_in_small_europe_and_dach_plus_group(): void
    {
        $this->assertSame('small_europe', $this->buckets->bucketFor('li'));
        $this->assertContains('li', $this->buckets->orderBuckets()['small_europe']);
        $this->assertSame(
            ['de', 'at', 'ch', 'lu', 'li'],
            $this->buckets->groupCodes('dach_plus')
        );
    }

    public function test_nordics_group_matches_nordics_order_bucket(): void
    {
        $this->assertSame(
            ['se', 'no', 'dk', 'fi', 'is'],
            $this->buckets->groupCodes('nordics')
        );
    }

    public function test_ordered_codes_have_no_duplicates_and_cover_allowlist(): void
    {
        $ordered = $this->buckets->orderedCodes();
        $this->assertSame($ordered, array_values(array_unique($ordered)));

        $allow = array_map('strtolower', config('markets.allowed_country_codes', []));
        sort($allow);
        $sortedOrdered = $ordered;
        sort($sortedOrdered);
        $this->assertSame($allow, $sortedOrdered);
    }
}
