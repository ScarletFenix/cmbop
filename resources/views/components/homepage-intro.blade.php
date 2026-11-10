@php
    $from = class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'formatFromEur')
        ? \App\Support\HomepageAiVisibility::formatFromEur()
        : (function_exists('format_money_amount')
            ? format_money_amount(20, 'EUR', ['decimals' => 0])
            : '€20');
    $publisherCount = class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'publisherCountLabel')
        ? \App\Support\HomepageAiVisibility::publisherCountLabel()
        : '1000+ publishers';
@endphp

<p class="slb-hero-intro">
    SEOLinkBuildings is a European guest-post and link building marketplace with {{ $publisherCount }}.
    Advertisers, SEO agencies and in-house teams buy editorial placements; publishers sell them on their own sites.
    Filter the catalog by country, language and niche, fund a EUR wallet, and check out at the listing price.
    Marketplace placements start from <strong data-home-from-price>{{ $from }}</strong>.
</p>
