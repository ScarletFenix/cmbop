<?php

namespace App\Support;

use Throwable;

/**
 * English homepage copy, facts, FAQ and JSON-LD for answer engines.
 * Numbers come from confirmed product facts only — never invented volume.
 */
class HomepageAiVisibility
{
    /** Public marketplace floor in EUR. Do not substitute a live catalog min. */
    public const MARKETPLACE_FROM_EUR = 20.0;

    /** Confirmed public publisher count. Never swap in advertiser or order volume. */
    public const PUBLISHER_COUNT_LABEL = '1000+ publishers';

    public static function isEnglishHome(): bool
    {
        $locale = function_exists('public_locale') ? (string) public_locale() : 'en';

        return in_array($locale, ['en', 'us'], true);
    }

    /**
     * Advertiser-facing marketplace floor in EUR (€20 on every public surface).
     */
    public static function marketplaceFromEur(): float
    {
        return self::MARKETPLACE_FROM_EUR;
    }

    public static function formatFromEur(?float $amount = null): string
    {
        $amount ??= self::MARKETPLACE_FROM_EUR;
        if ($amount <= 0) {
            $amount = self::MARKETPLACE_FROM_EUR;
        }
        if (function_exists('format_money_amount')) {
            return format_money_amount($amount, 'EUR', ['decimals' => 0]);
        }

        return '€'.number_format($amount, 0);
    }

    public static function publisherCountLabel(): string
    {
        return self::PUBLISHER_COUNT_LABEL;
    }

    public static function countryCount(): int
    {
        try {
            if (class_exists(CountryLander::class) && method_exists(CountryLander::class, 'slugs')) {
                return count(CountryLander::slugs());
            }
        } catch (Throwable) {
        }

        return 0;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function facts(): array
    {
        $from = self::formatFromEur();
        $countries = self::countryCount();
        $legal = class_exists(BrandOrganization::class)
            ? (BrandOrganization::confirmedLegalName() ?: 'Teqno Ltd')
            : 'Teqno Ltd';
        $registration = (string) config('billing.company.registration_no', config('company.registration_no', '17392540'));
        $founder = class_exists(BrandPerson::class) ? BrandPerson::publicName() : 'M. Arslan';
        $founded = trim((string) config('company.founded', '2024'));

        $facts = [
            ['label' => 'Publishers', 'value' => self::publisherCountLabel()],
            ['label' => 'Company', 'value' => $legal.' '.$registration],
            ['label' => 'Founded', 'value' => $founded !== '' ? $founded : '2024'],
            ['label' => 'Founder', 'value' => $founder],
            ['label' => 'Registered office', 'value' => '20 Wenlock Road, London N1 7GU'],
            ['label' => 'Publisher listing', 'value' => 'Free — publishers pay nothing'],
            ['label' => 'Checkout', 'value' => 'EUR wallet; funds held until approval'],
        ];
        if ($countries > 0) {
            array_splice($facts, 1, 0, [[
                'label' => 'European markets',
                'value' => $countries.' country landers',
            ]]);
        }
        array_splice($facts, 1, 0, [[
            'label' => 'Marketplace from',
            'value' => 'from '.$from,
        ]]);

        return $facts;
    }

    /**
     * Question-style commercial prompts. Answers stay inside confirmed product facts.
     *
     * @return list<array{q: string, a: string}>
     */
    public static function faqItems(): array
    {
        $from = self::formatFromEur();
        $legal = class_exists(BrandOrganization::class)
            ? (BrandOrganization::confirmedLegalName() ?: 'Teqno Ltd')
            : 'Teqno Ltd';
        $registration = (string) config('billing.company.registration_no', config('company.registration_no', '17392540'));
        $founder = class_exists(BrandPerson::class) ? BrandPerson::publicName() : 'M. Arslan';
        $fromClause = ' Marketplace placements start from '.$from.' in EUR.';
        $germanyClause = ' Marketplace placements start from '.$from.'; German listing prices vary by site.';
        $spainClause = ' Marketplace placements start from '.$from.'; Spanish listing prices vary by site.';

        return [
            [
                'q' => 'What is the best guest post marketplace for Europe?',
                'a' => 'SEOLinkBuildings is a European guest-post and sponsored-article marketplace with '.self::publisherCountLabel().'. Advertisers and agencies buy editorial placements in Germany, the UK, Spain, France, Italy, the Nordics and other European markets. Marketplace placements start from '.$from.' in EUR. You browse the catalog, fund a wallet, and track every order to a live URL. Compare country from-prices on the EU guest-post price index rather than treating any marketplace as universally “best”.',
            ],
            [
                'q' => 'Can I buy German backlinks on SEOLinkBuildings?',
                'a' => 'Yes. Open the Germany guest-post lander or filter the catalog for Germany after you register. Listings are reviewed before they appear. You pay in EUR from your wallet; the publisher delivers the live URL; funds stay held until you approve, or for 72 hours after they submit it.'.$germanyClause.' Austria and Switzerland have their own landers if you need DACH coverage beyond Germany.',
            ],
            [
                'q' => 'How much do sponsored articles in Spain cost?',
                'a' => 'There is no single Spain rate. Each publisher sets a price, shown in EUR before checkout.'.$spainClause.' Managed Digital PR packages, if you want the team to place coverage for you, start at €499 a month. See sponsored articles and the EU price index for how marketplace placements differ from those packages.',
            ],
            [
                'q' => 'Do publishers pay to list on SEOLinkBuildings?',
                'a' => 'No. Publishers pay nothing to list a site. Create a publisher account, add your website and price, and the team reviews it. After approval the listing can appear in the catalog. When an advertiser orders, funds are held until they approve the live URL, or automatically 72 hours after you submit it. The amount you set is then credited to your EUR wallet.',
            ],
            [
                'q' => 'How does ordering, escrow and approval work?',
                'a' => 'Create an advertiser account, add funds to your EUR wallet, and buy a listing. SEOLinkBuildings holds the payment instead of paying the publisher immediately. The publisher publishes the article and submits the live URL. You review it and approve; only then are funds released. If you do not respond, the order is approved automatically after 72 hours. Do not approve until the URL matches what you ordered.'.$fromClause,
            ],
            [
                'q' => 'Who operates SEOLinkBuildings?',
                'a' => 'SEOLinkBuildings is the trading name of '.$legal.', a UK private limited company ('.$registration.') founded in 2024 by '.$founder.'. The registered address is 20 Wenlock Road, London N1 7GU. Support is support@seolinkbuildings.com. The same company facts are on the About page and in /llms.txt for assistants.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function faqSchema(): array
    {
        $pageUrl = function_exists('localized_url') ? localized_url('/') : url('/');
        $orgId = class_exists(BrandOrganization::class) ? BrandOrganization::organizationId() : rtrim((string) url('/'), '/').'/#organization';
        $siteId = class_exists(BrandOrganization::class) ? BrandOrganization::websiteId() : rtrim((string) url('/'), '/').'/#website';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => rtrim((string) $pageUrl, '/').'/#homepage-faq',
            'url' => $pageUrl,
            'inLanguage' => class_exists(PublicI18n::class) ? PublicI18n::htmlLang() : 'en-GB',
            'isPartOf' => ['@id' => $siteId],
            'about' => ['@id' => $orgId],
            'mainEntity' => array_map(static function (array $item): array {
                return [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['a'],
                    ],
                ];
            }, self::faqItems()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function websiteSchema(): array
    {
        $home = function_exists('localized_url') ? localized_url('/') : url('/');
        $marketplace = function_exists('localized_url') ? localized_url('marketplace') : url('/marketplace');
        $orgId = class_exists(BrandOrganization::class) ? BrandOrganization::organizationId() : rtrim((string) url('/'), '/').'/#organization';
        $siteId = class_exists(BrandOrganization::class) ? BrandOrganization::websiteId() : rtrim((string) url('/'), '/').'/#website';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $siteId,
            'name' => 'SEOLinkBuildings',
            'url' => $home,
            'inLanguage' => class_exists(PublicI18n::class) ? PublicI18n::htmlLang() : 'en-GB',
            'publisher' => ['@id' => $orgId],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $marketplace.'?search={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
            'hasPart' => [
                [
                    '@type' => 'AboutPage',
                    'name' => trans()->has('messages.about_title') ? (string) __('messages.about_title') : 'About SEOLinkBuildings',
                    'url' => function_exists('localized_url') ? localized_url('about') : url('/about'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serviceSchema(): array
    {
        $home = function_exists('localized_url') ? localized_url('/') : url('/');
        $orgId = class_exists(BrandOrganization::class) ? BrandOrganization::organizationId() : rtrim((string) url('/'), '/').'/#organization';
        $from = self::marketplaceFromEur();
        $fromPrice = (string) (int) round($from);
        $description = function_exists('welcome_bonus_message')
            ? welcome_bonus_message('meta_home_description', 'meta_home_description_off')
            : (string) __('messages.meta_home_description');

        $offers = [
            [
                '@type' => 'Offer',
                'name' => 'Marketplace guest-post placement',
                'description' => 'Editorial guest post or sponsored article on a verified publisher site. Price is the listing checkout amount in EUR.',
                'price' => $fromPrice,
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => function_exists('localized_url') ? localized_url('marketplace') : url('/marketplace'),
            ],
            [
                '@type' => 'Offer',
                'name' => 'Digital PR Starter',
                'price' => '499',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => function_exists('localized_url') ? localized_url('pricing') : url('/pricing'),
            ],
            [
                '@type' => 'Offer',
                'name' => 'Digital PR Growth',
                'price' => '1499',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => function_exists('localized_url') ? localized_url('pricing') : url('/pricing'),
            ],
            [
                '@type' => 'Offer',
                'name' => 'Digital PR Authority',
                'price' => '2799',
                'priceCurrency' => 'EUR',
                'availability' => 'https://schema.org/InStock',
                'url' => function_exists('localized_url') ? localized_url('pricing') : url('/pricing'),
            ],
        ];
        $catalog = [
            '@type' => 'OfferCatalog',
            'name' => 'SEOLinkBuildings placements',
            'itemListElement' => $offers,
            'lowPrice' => $fromPrice,
            'priceCurrency' => 'EUR',
        ];

        $areas = [
            ['@type' => 'Continent', 'name' => 'Europe'],
        ];
        foreach (['Germany', 'United Kingdom', 'Spain', 'France', 'Italy'] as $country) {
            $areas[] = ['@type' => 'Country', 'name' => $country];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            '@id' => rtrim((string) $home, '/').'/#marketplace-service',
            'name' => 'SEOLinkBuildings guest post marketplace',
            'serviceType' => 'Guest post and link building marketplace',
            'description' => $description,
            'url' => $home,
            'provider' => ['@id' => $orgId],
            'areaServed' => $areas,
            'hasOfferCatalog' => $catalog,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function breadcrumbSchema(): array
    {
        $home = function_exists('localized_url') ? localized_url('/') : url('/');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => trans()->has('messages.home') ? (string) __('messages.home') : 'Home',
                    'item' => $home,
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function jsonLdBlocks(): array
    {
        $blocks = [
            self::websiteSchema(),
            self::serviceSchema(),
            self::breadcrumbSchema(),
        ];
        if (self::isEnglishHome()) {
            $blocks[] = self::faqSchema();
        }

        return $blocks;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function trustLinks(): array
    {
        $url = static function (string $english, ?string $absolute = null): string {
            if ($absolute !== null) {
                return url($absolute);
            }

            return function_exists('localized_url') ? localized_url($english) : url('/'.$english);
        };

        return [
            ['label' => 'About', 'url' => $url('about')],
            ['label' => 'Contact', 'url' => $url('contact')],
            ['label' => 'Is SEOLinkBuildings safe?', 'url' => $url('', '/is-seolinkbuildings-safe')],
            ['label' => 'FAQ', 'url' => $url('faq')],
            ['label' => 'EU price index', 'url' => $url('', '/guest-post-prices-europe')],
            ['label' => 'llms.txt', 'url' => $url('', '/llms.txt')],
            ['label' => 'Become a publisher', 'url' => $url('become-a-publisher')],
            ['label' => 'Why choose us', 'url' => $url('why-choose-us')],
        ];
    }
}
