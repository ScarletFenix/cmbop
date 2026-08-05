<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicCopyIntegrityTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return ['en', 'de', 'fr', 'nl'];
    }

    private function langPath(string $locale): string
    {
        return resource_path('lang/'.$locale.'/messages.php');
    }

    /**
     * Duplicate keys are silent: the later definition wins and a whole page can
     * end up rendering another page's copy.
     */
    public function test_no_locale_file_declares_a_key_twice(): void
    {
        foreach ($this->locales() as $locale) {
            $source = file_get_contents($this->langPath($locale));
            preg_match_all("/^\s*'([a-z0-9_]+)'\s*=>/mi", $source, $matches);

            $counts = array_count_values($matches[1]);
            $duplicates = array_keys(array_filter($counts, fn ($n) => $n > 1));

            $this->assertSame([], $duplicates, $locale.' declares duplicate keys: '.implode(', ', $duplicates));
        }
    }

    public function test_every_locale_defines_the_same_keys(): void
    {
        $reference = array_keys(require $this->langPath('en'));
        sort($reference);

        foreach (['de', 'fr', 'nl'] as $locale) {
            $keys = array_keys(require $this->langPath($locale));
            sort($keys);

            $missing = array_diff($reference, $keys);
            $extra = array_diff($keys, $reference);

            $this->assertSame([], array_values($missing), $locale.' is missing keys: '.implode(', ', $missing));
            $this->assertSame([], array_values($extra), $locale.' has unknown keys: '.implode(', ', $extra));
        }
    }

    public function test_privacy_and_terms_no_longer_share_section_keys(): void
    {
        $messages = require $this->langPath('en');

        // Privacy owns the namespaced set; terms keeps the bare one.
        $this->assertSame('1. Information We Collect', $messages['privacy_section1_title']);
        $this->assertSame('6. Your Rights Under GDPR', $messages['privacy_section6_title']);
        $this->assertSame('Acceptance of Terms', $messages['section1_title']);
        $this->assertSame('Payment and Billing', $messages['section6_title']);

        $blade = file_get_contents(resource_path('views/pages/privacy-policy.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/messages\.section\d+_/', $blade);
    }

    public function test_privacy_page_renders_privacy_copy_not_terms_copy(): void
    {
        $html = $this->get('/privacy-policy')->assertOk()->getContent();

        $this->assertStringContainsString('1. Information We Collect', $html);
        $this->assertStringContainsString('6. Your Rights Under GDPR', $html);
        $this->assertStringNotContainsString('Acceptance of Terms', $html);
        $this->assertStringNotContainsString('messages.privacy_section', $html);
    }

    public function test_terms_page_still_renders_its_own_copy(): void
    {
        $html = $this->get('/terms-of-services')->assertOk()->getContent();

        $this->assertStringContainsString('Acceptance of Terms', $html);
        $this->assertStringContainsString('Payment and Billing', $html);
        $this->assertStringNotContainsString('messages.section', $html);
    }

    public function test_refund_policy_explains_payments_and_publisher_pricing(): void
    {
        $blade = file_get_contents(resource_path('views/pages/refund-policy.blade.php'));
        $this->assertStringContainsString('range(1, 8)', $blade);

        foreach ($this->locales() as $locale) {
            $messages = require $this->langPath($locale);
            foreach ([7, 8] as $section) {
                $this->assertNotEmpty($messages['refund_section_'.$section.'_title'] ?? '', $locale.' section '.$section.' title');
                $this->assertNotEmpty($messages['refund_section_'.$section.'_body'] ?? '', $locale.' section '.$section.' body');
            }
        }

        $html = $this->get('/refund-policy')->assertOk()->getContent();
        $this->assertStringContainsString('How payments are handled', $html);
        $this->assertStringContainsString('Publisher pricing and payouts', $html);
        $this->assertStringNotContainsString('messages.refund_section', $html);
    }

    public function test_publisher_page_covers_earnings_timing_and_payouts(): void
    {
        $html = $this->get('/become-a-publisher')->assertOk()->getContent();

        foreach ([
            'From listing a site to getting paid',
            'What you earn',
            'When you get paid',
            'How you get your money',
            'Good to know',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, 'Missing section: '.$heading);
        }

        // The facts publishers ask about.
        $this->assertStringContainsString('72 hours', $html);
        $this->assertStringContainsString('30 days', $html);
        $this->assertStringContainsString('Wise', $html);
        $this->assertStringContainsString('No minimum threshold', $html);
        $this->assertStringNotContainsString('messages.become_publisher', $html);
    }

    /**
     * The fee is intentionally not published. Publishers are told they keep the
     * price they set instead.
     */
    public function test_public_pages_do_not_disclose_platform_fee_percentages(): void
    {
        $tiers = collect(config('pricing.fee_tiers', []))
            ->pluck('percent')
            ->map(fn ($p) => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.'))
            ->unique()
            ->all();

        $this->assertNotEmpty($tiers, 'Expected configured fee tiers to compare against.');

        foreach (['/become-a-publisher', '/refund-policy', '/pricing'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            foreach ($tiers as $percent) {
                $this->assertStringNotContainsString(
                    $percent.'% platform fee',
                    $html,
                    $path.' should not publish the platform fee'
                );
                $this->assertStringNotContainsString(
                    'platform fee of '.$percent.'%',
                    $html,
                    $path.' should not publish the platform fee'
                );
            }
        }
    }

    public function test_publisher_page_states_publishers_keep_the_price_they_set(): void
    {
        $messages = require $this->langPath('en');

        $this->assertStringContainsString('You set your own price', $messages['become_publisher_earnings_point_1_title']);
        $this->assertStringContainsString('keep the price you set', $messages['become_publisher_earnings_point_2_title']);
        $this->assertStringContainsString(
            'credited to your wallet',
            $messages['become_publisher_earnings_point_2_body']
        );
    }
}
