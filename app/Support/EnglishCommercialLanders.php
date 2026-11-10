<?php

namespace App\Support;

/**
 * Unprefixed English commercial landers (agencies, sponsored articles,
 * alternatives). Locale twins live on *MoneyLanders classes; this class
 * is not auto-registered in MoneyLanderCatalog so sitemap/routes stay
 * unprefixed.
 */
class EnglishCommercialLanders
{
    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /**
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            'agencies' => '/for-agencies',
            'for-agency' => '/for-agencies',
            'link-building-agencies' => '/for-agencies',
            'advertorials' => '/sponsored-articles',
            'sponsored-posts' => '/sponsored-articles',
            'advertorial' => '/sponsored-articles',
            'marketplace-alternatives' => '/alternatives',
        ];
    }

    public static function isSlug(string $segment): bool
    {
        $segment = trim($segment, '/');

        return $segment !== '' && in_array($segment, self::slugs(), true);
    }

    public static function url(string $slug): string
    {
        return url('/'.trim($slug, '/'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $slug = trim($slug, '/');

        return self::pages()[$slug] ?? null;
    }

    /**
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function clusterLinks(?string $current = null): array
    {
        $items = [
            ['slug' => 'home', 'label' => 'Home', 'url' => url('/')],
            ['slug' => 'marketplace', 'label' => 'Marketplace', 'url' => url('/marketplace')],
            ['slug' => 'for-agencies', 'label' => 'For agencies', 'url' => self::url('for-agencies')],
            ['slug' => 'sponsored-articles', 'label' => 'Sponsored articles', 'url' => self::url('sponsored-articles')],
            ['slug' => 'alternatives', 'label' => 'How we differ', 'url' => self::url('alternatives')],
            ['slug' => 'pricing', 'label' => 'Pricing', 'url' => url('/pricing')],
            ['slug' => 'guest-post-prices-europe', 'label' => 'EU price index', 'url' => url('/guest-post-prices-europe')],
            ['slug' => 'why-choose-us', 'label' => 'Why choose us', 'url' => url('/why-choose-us')],
        ];

        if ($current === null || $current === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item) => $item['slug'] !== $current
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        $marketplace = '/marketplace';
        $pricing = '/pricing';
        $how = '/how-it-works';
        $register = '/register';
        $about = '/about';
        $agencies = '/for-agencies';
        $sponsored = '/sponsored-articles';
        $alts = '/alternatives';
        $index = '/guest-post-prices-europe';
        $safe = '/is-seolinkbuildings-safe';
        $refund = '/refund-policy';
        $de = '/de/gastbeitrag-kaufen';
        $fr = '/fr/acheter-guest-post';
        $nl = '/nl/gastblog-kopen';
        $es = '/es/comprar-guest-post';
        $it = '/it/comprare-guest-post';
        $pl = '/pl/wpis-goscinny';

        return [
            'for-agencies' => [
                'kicker' => 'B2B advertiser account',
                'h1' => 'Link building for SEO and digital PR agencies',
                'subtitle' => 'A self-service European guest-post and sponsored-article catalog for agencies that rebill clients. One EUR wallet, tracked orders, live URLs — not a reseller storefront with your logo on the public site.',
                'meta_title' => 'Link Building for SEO Agencies | SEOLinkBuildings',
                'meta_description' => 'Guest posts for SEO agencies: 1000+ publishers, EUR wallet, tracked live URLs, UK company invoices. You pick the sites; we are not a branded reseller portal.',
                'teaser_countries' => ['de', 'fr', 'nl', 'es', 'it', 'uk', 'pl'],
                'teaser_title' => 'Inventory you can rebill',
                'teaser_subtitle' => 'The same verified listings an in-house advertiser sees. You stay the account; brands live in your projects and orders.',
                'intro' => [
                    'SEOLinkBuildings is a two-sided marketplace with 1000+ publishers, not an agency that chooses sites for you. If you run SEO or link-building campaigns for clients, you stay in control: filter the catalog, pay in euro, send the brief, and hand the live URL back to the client.',
                    'Operational white label means the end client does not have to create a marketplace account. It is not a reseller programme with your brand on the public website, and we do not invent a second “agency price list”. Checkout is the listing price in EUR.',
                ],
                'points' => [
                    [
                        'title' => 'One wallet, several campaigns',
                        'body' => 'Fund euros (card or bank transfer, when those methods are active) and split the balance across orders. You do not open a new invoice for every site unless you want that internally.',
                    ],
                    [
                        'title' => 'Orders and invoices',
                        'body' => 'Wallet and order invoices download from advertiser billing when the product issues them. The legal entity is Teqno Ltd (UK) — trading name SEOLinkBuildings. Invoices may name Teqno Ltd as partner of SEOLinkBuildings; emails stay SLB-branded.',
                    ],
                    [
                        'title' => 'English dashboard for every role',
                        'body' => 'Filters, metrics, order chat, and the live URL sit on the order. After login the SaaS UI is English for advertisers, publishers, and agencies.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Agency versus marketplace',
                        'body' => 'An agency decides which sites a client should buy. A marketplace shows the sites to the buyer. SEOLinkBuildings is the second. If you are the agency, you put the first on top of the second. Compare that with <a href="'.$alts.'">how we differ from opaque packages and other marketplaces</a>.',
                    ],
                    [
                        'h2' => 'Countries we cover',
                        'body' => 'Public country landers include Germany, the UK, France, the Netherlands, Spain, Italy, Portugal, Switzerland, Austria, Poland, Romania, Greece, Denmark, Sweden, Norway, Bulgaria, Hungary, and Estonia. Locale buy pages (for example <a href="'.$de.'">Germany</a>, <a href="'.$fr.'">France</a>, <a href="'.$nl.'">the Netherlands</a>, <a href="'.$es.'">Spain</a>, <a href="'.$it.'">Italy</a>, <a href="'.$pl.'">Poland</a>) sit on path prefixes. Catalog filters after login are the live inventory — we do not invent a count of advertisers or monthly orders.',
                    ],
                    [
                        'h2' => 'How to start',
                        'body' => 'Create an advertiser account, fund the wallet, filter the market you need, and order. Workflow: <a href="'.$how.'">how it works</a>. Catalog: <a href="'.$marketplace.'">marketplace</a>. Support: the contact page. Company facts: <a href="'.$about.'">About</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Can I hide the marketplace from my client?',
                        'a' => 'Yes, by running the account yourself. We do not ship a branded white-label portal.',
                    ],
                    [
                        'q' => 'Do you issue invoices in my client’s name?',
                        'a' => 'No. Billing follows the UK company on the product. Download the documents from advertiser billing and rebill on your side.',
                    ],
                    [
                        'q' => 'Is there a separate agency rate card?',
                        'a' => 'No. Checkout is the listing price. Managed Digital PR packages on Pricing are a different product — numbered campaigns, not a sack of anonymous URLs.',
                    ],
                ],
                'cta_primary' => ['label' => 'Create an agency account', 'url' => $register],
                'cta_secondary' => ['label' => 'Open the marketplace', 'url' => $marketplace],
                'see_also' => [
                    ['label' => 'Sponsored articles', 'url' => $sponsored],
                    ['label' => 'How we differ', 'url' => $alts],
                    ['label' => 'Pricing and packages', 'url' => $pricing],
                    ['label' => 'EU guest-post price index', 'url' => $index],
                ],
            ],
            'sponsored-articles' => [
                'kicker' => 'Paid publication',
                'h1' => 'Sponsored articles and advertorials on publisher sites',
                'subtitle' => 'Sponsored post, advertorial, or native article: the same European marketplace checkout as a guest post — EUR price, briefing, live URL, and the labelling the listing actually allows.',
                'meta_title' => 'Sponsored Articles & Advertorials | SEOLinkBuildings',
                'meta_description' => 'Buy sponsored articles and advertorials from 1000+ publishers. Same catalog checkout as guest posts, in EUR, with briefing and a tracked live URL — no ranking promise.',
                'teaser_countries' => ['de', 'fr', 'nl', 'es', 'it', 'uk'],
                'teaser_title' => 'Sites for paid publications',
                'teaser_subtitle' => 'The same catalog as guest posts. The difference is commercial vocabulary: advertorial, sponsored, native — plus disclosure and link attributes on the listing.',
                'intro' => [
                    '“Buy a sponsored article”, “advertorial”, and “native advertising” describe a paid publication on a site you do not own. On SEOLinkBuildings that is the same catalog of 1000+ publishers, with disclosure and link attributes as the publisher lists them.',
                    'We do not sell rankings, a national press wire, or Google News placement. We sell a publication with visible rules, euro checkout, and a live URL on the order.',
                ],
                'points' => [
                    [
                        'title' => 'Same catalog, different words',
                        'body' => 'Filter niche, language, and price. The publisher’s media kit is on the listing: accepted topics, link count, turnaround, and whether the post is marked sponsored.',
                    ],
                    [
                        'title' => 'Sponsored versus guest post',
                        'body' => 'If you pay to appear, treat it as sponsored — even if the invoice says guest post. Google expects paid placements to be qualified (rel="sponsored" or rel="nofollow") when that is how the site marks them.',
                    ],
                    [
                        'title' => 'Editorial article, not a banner',
                        'body' => 'You supply copy or a brief. The publisher publishes in their CMS. That is not display advertising and not a brand mention in a daily newspaper unless that domain is in the catalog and accepts the brief.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'How to order',
                        'body' => 'Register, pick sites, pay from the wallet, send the material. Status stays on the order until the live URL. Guest-post intent uses the same checkout; country English landers and locale pages (Germany, France, the Netherlands, Spain, Italy, and others) list the inventory by market. Workflow: <a href="'.$how.'">how it works</a>.',
                    ],
                    [
                        'h2' => 'Pricing and what you can verify',
                        'body' => 'Each listing has an advertiser checkout price in EUR (publisher price plus the platform fee). There is no public PDF rate card of every domain. Country medians: <a href="'.$index.'">EU guest-post price index</a>. Packages on <a href="'.$pricing.'">Pricing</a> are managed Digital PR campaigns, not anonymous URL bundles. Agencies: <a href="'.$agencies.'">for agencies</a>.',
                    ],
                    [
                        'h2' => 'If a live link is removed',
                        'body' => 'Advertisers can report a removed or broken placement for 30 days after completion. When we uphold the report, the publisher payout is clawed back and refunded on the documented path. Details: <a href="'.$refund.'">refund policy</a> and <a href="'.$safe.'">Is SEOLinkBuildings safe?</a>.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Is this a different product from a guest post?',
                        'a' => 'Not as a catalog SKU. Yes as a label and in how the paid link is declared.',
                    ],
                    [
                        'q' => 'Can I demand dofollow on a sponsored article?',
                        'a' => 'Only if the listing allows it. Many publishers mark paid publications. That is the site’s policy, not a marketplace bug.',
                    ],
                    [
                        'q' => 'Do you place articles on national news wires?',
                        'a' => 'Only if that domain is in the catalog. We do not promise Google News or a press-release circuit.',
                    ],
                ],
                'cta_primary' => ['label' => 'Open the catalog after you register', 'url' => $register],
                'cta_secondary' => ['label' => 'Browse the marketplace', 'url' => $marketplace],
                'see_also' => [
                    ['label' => 'For agencies', 'url' => $agencies],
                    ['label' => 'EU price index', 'url' => $index],
                    ['label' => 'How we differ', 'url' => $alts],
                ],
            ],
            'alternatives' => [
                'kicker' => 'How we differ',
                'h1' => 'How SEOLinkBuildings differs from agencies and other marketplaces',
                'subtitle' => 'A European guest-post and sponsored-article marketplace: filterable catalog, EUR wallet, live URL on the order, 30-day removal reports. We do not invent competitor stats or our own order volume.',
                'meta_title' => 'How SEOLinkBuildings Differs | Guest Post Marketplaces',
                'meta_description' => 'How SEOLinkBuildings differs from opaque packages: 1000+ publishers, EUR wallet, tracked live URLs, 30-day removal reports — no invented advertiser or order counts.',
                'teaser_countries' => ['de', 'fr', 'nl', 'es', 'it', 'uk', 'pl'],
                'teaser_title' => 'The same public catalog',
                'teaser_subtitle' => 'We do not clone another platform’s inventory. After you register you see our listings with EUR checkout prices.',
                'intro' => [
                    'Searches for “guest post marketplace alternatives” or “how we differ from link-building agencies” want a comparison. SEOLinkBuildings has 1000+ publishers. This page does not claim we are “cheaper” or “larger” than a named competitor. It lists what you can check on SEOLinkBuildings today.',
                    'Brand: SEOLinkBuildings. Legal entity: Teqno Ltd (company number 17392540), registered in England and Wales, 20 Wenlock Road, London N1 7GU. Founded 2024. Catalog and checkout in EUR. Spanish-language comparison copy lives on <a href="/es/alternativas">/es/alternativas</a> — one conquest page, not a doorway per brand.',
                ],
                'points' => [
                    [
                        'title' => 'What you can verify',
                        'body' => 'Advertiser price per listing, EUR wallet, live URL on the order, country/language/niche filters, UK company on About, 30-day link-removal reports on completed orders, and country medians on the EU price index.',
                    ],
                    [
                        'title' => 'What we do not claim',
                        'body' => 'We do not publish invented media counts, advertiser counts, monthly order volume, or “cheaper than X” without a public source. Catalog domains stay masked until you register.',
                    ],
                    [
                        'title' => 'Marketplace, not a closed package',
                        'body' => 'You choose publishers. We do not sell mystery “20× DR50” bundles. Numbered packages on Pricing are managed Digital PR campaigns run by our team, with amounts shown on that page.',
                    ],
                ],
                'sections' => [
                    [
                        'h2' => 'Compared with an opaque agency package',
                        'body' => 'An agency may pick sites for you and invoice a campaign. Here the catalog is visible after login: niche, language, metrics the listing actually has, and the checkout price. You can still be the agency — <a href="'.$agencies.'">for agencies</a> — and keep the client off the public site.',
                    ],
                    [
                        'h2' => 'Compared with other guest-post marketplaces',
                        'body' => 'Other platforms exist. Inventory is ours; we do not copy or guarantee a third party’s catalog. Compare the flow you can inspect: register, EUR wallet, order with a live URL, refund and removal rules. Country pages and locale prefixes (Germany, France, the Netherlands, Spain, Italy, Poland, Nordics, and others) describe coverage. Medians: <a href="'.$index.'">EU guest-post price index</a>.',
                    ],
                    [
                        'h2' => 'Wallet, escrow, and removals',
                        'body' => 'When you order, funds are held before the publisher starts. The publisher is paid after you approve the live URL, or automatically 72 hours after they submit it if you do not respond. Completed placements can be reported for 30 days if the live link is removed. <a href="'.$safe.'">Is SEOLinkBuildings safe?</a> and the <a href="'.$refund.'">refund policy</a> document that. We do not promise rankings.',
                    ],
                ],
                'faqs' => [
                    [
                        'q' => 'Are you cheaper than other marketplaces?',
                        'a' => 'We do not claim that. Price is the listing in EUR. Compare it in the catalog after you register, and use the EU index for country medians.',
                    ],
                    [
                        'q' => 'Do you have the same publishers as another platform?',
                        'a' => 'The inventory is ours. We do not copy or guarantee a third party’s catalog.',
                    ],
                    [
                        'q' => 'Is there a page per competitor?',
                        'a' => 'No. One English alternatives page, plus the Spanish /es/alternativas landing, avoids weak doorways and cannibalisation.',
                    ],
                ],
                'cta_primary' => ['label' => 'See the catalog after you register', 'url' => $register],
                'cta_secondary' => ['label' => 'Marketplace overview', 'url' => $marketplace],
                'see_also' => [
                    ['label' => 'For agencies', 'url' => $agencies],
                    ['label' => 'Sponsored articles', 'url' => $sponsored],
                    ['label' => 'Pricing', 'url' => $pricing],
                    ['label' => 'About Teqno Ltd', 'url' => $about],
                ],
            ],
        ];
    }
}
