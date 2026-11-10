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
    $legal = class_exists(\App\Support\BrandOrganization::class)
        ? (\App\Support\BrandOrganization::confirmedLegalName() ?: 'Teqno Ltd')
        : 'Teqno Ltd';
    $registration = (string) config('billing.company.registration_no', config('company.registration_no', '17392540'));
    $founder = class_exists(\App\Support\BrandPerson::class) ? \App\Support\BrandPerson::publicName() : 'M. Arslan';
    $countries = class_exists(\App\Support\HomepageAiVisibility::class)
        ? \App\Support\HomepageAiVisibility::countryCount()
        : 0;
    $aboutUrl = function_exists('localized_url') ? localized_url('about') : url('/about');
    $publisherUrl = function_exists('localized_url') ? localized_url('become-a-publisher') : url('/become-a-publisher');
    $howUrl = function_exists('localized_url') ? localized_url('how-it-works') : url('/how-it-works');
    $indexUrl = url('/guest-post-prices-europe');
@endphp

<p class="slb-hero-intro">
    SEOLinkBuildings is a European guest-post and link building marketplace with {{ $publisherCount }}.
    Advertisers, SEO agencies and in-house teams buy editorial placements; publishers sell them on their own sites.
    Filter the catalog by country, language and niche, fund a EUR wallet, and check out at the listing price.
    Marketplace placements start from <strong data-home-from-price>{{ $from }}</strong>.
</p>
<p class="slb-hero-intro">
    After you buy, payment is held until you approve the live URL, or for 72 hours after the publisher submits it —
    see <a href="{{ $howUrl }}">how ordering works</a>.
    @if($countries > 0)
        Dedicated pages cover {{ $countries }} European markets, with live from-prices on the
        <a href="{{ $indexUrl }}">EU guest-post price index</a>.
    @else
        Country from-prices are on the <a href="{{ $indexUrl }}">EU guest-post price index</a>.
    @endif
    <a href="{{ $publisherUrl }}">Publishers pay nothing</a> to list a site.
</p>
<p class="slb-hero-intro">
    The marketplace is operated by {{ $legal }} (company number {{ $registration }}), founded in 2024 by {{ $founder }},
    at 20 Wenlock Road, London N1 7GU.
    Company details are on the <a href="{{ $aboutUrl }}">About</a> page and in <a href="{{ url('/llms.txt') }}">llms.txt</a>.
</p>
