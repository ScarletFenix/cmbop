@extends('advertiser.layouts.app')

@push('page-styles')
    <link href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: '1' }}" rel="stylesheet">
    {{-- Critical catalog guards: if a stale CDN copy of catalog.css wins the
         race, Chrome still must not paint a stuck busy layer, teal NEW pills,
         or intrinsic-size metric logos. --}}
    <style id="catalog-critical">
        .catalog-results-busy[hidden]{display:none!important}
        .catalog-page button.site-badge-new,
        .catalog-page .site-badge-new{background:#ef4444!important;background-image:none!important;color:#fff!important;border:0!important}
        .catalog-page .metric-source{width:var(--metric-source-size,20px);height:var(--metric-source-size,20px);max-width:var(--metric-source-size,20px);max-height:var(--metric-source-size,20px);overflow:hidden}
        .catalog-page .metric-source img{width:var(--metric-source-size,20px);height:var(--metric-source-size,20px);max-width:var(--metric-source-size,20px);max-height:var(--metric-source-size,20px)}
        .catalog-page .categories-column{display:flex;flex-direction:row;flex-wrap:wrap;justify-content:center;align-items:center;gap:4px}
        /* Metric bars must paint even if a stale CDN copy of catalog.css wins. */
        .catalog-page .catalog-metric{display:inline-flex;flex-direction:column;align-items:center;gap:4px;min-width:3.5rem}
        .catalog-page .catalog-metric__bar{display:block!important;width:3.25rem;max-width:100%;height:6px;border-radius:999px;background:#d5dbe3;overflow:hidden}
        .catalog-page .catalog-metric__fill{display:block!important;height:100%;border-radius:inherit;background:#3faeb2}
        .catalog-page .catalog-metric--da .catalog-metric__fill{background:#24abe2}
        .catalog-page .catalog-country{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px}
        .catalog-page .catalog-country__flag{font-size:22px;line-height:1}
        .catalog-page .catalog-table tbody td,
        .catalog-page .catalog-table tbody td.catalog-stat-cell{vertical-align:middle}
    </style>
@endpush

@section('content')

@php
    use Illuminate\Support\Str;
    $sites = $sites ?? collect();
    $favorites = $favorites ?? [];
    $blacklist = $blacklist ?? [];

    if (!function_exists('getCountryFlag')) {
        function getCountryFlag($countryCode){
            $code = strtoupper((string) $countryCode);
            if ($code === 'UK') $code = 'GB';
            if (strlen($code) < 2) return '';
            return mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        }
    }

    if (!function_exists('getLanguageFlag')) {
        function getLanguageFlag($languageCode){
            $languageToCountry = [
                'en' => 'us', 'es' => 'es', 'fr' => 'fr', 'de' => 'de',
                'it' => 'it', 'pt' => 'pt', 'nl' => 'nl', 'zh' => 'cn', 'ar' => 'ae',
                'pl' => 'pl', 'sv' => 'se', 'da' => 'dk', 'no' => 'no',
                'fi' => 'fi', 'el' => 'gr', 'cs' => 'cz', 'hu' => 'hu',
                'ro' => 'ro', 'bg' => 'bg', 'hr' => 'hr', 'sk' => 'sk',
                'sl' => 'si', 'lt' => 'lt', 'lv' => 'lv', 'et' => 'ee',
                'ca' => 'es', 'gl' => 'es', 'eu' => 'es', 'cy' => 'gb',
                'gd' => 'gb', 'ga' => 'ie', 'lb' => 'lu', 'rm' => 'ch',
                'mt' => 'mt',
            ];
            $countryCode = $languageToCountry[strtolower((string) $languageCode)] ?? 'us';
            return getCountryFlag($countryCode);
        }
    }
@endphp

{{-- catalog-page scopes this page's stylesheet. Without it, rules for .table,
     .badge and .form-control reached the cart drawer and the shell chrome. --}}
<div class="container-fluid catalog-page">
    @include('components.ad-banners', ['placement' => 'marketplace', 'audience' => 'advertiser'])

    @if(request()->boolean('wizard') && ! empty(\App\Http\Controllers\Advertiser\GuestPostWizardController::stateFromSession()['language']))
        @include('advertiser.wizard._catalog_chrome')
    @elseif(!empty($orderingSubmission))
        @include('advertiser.partials.ordering-path', [
            'step' => 2,
            'title' => 'Place a guest post · Publishers',
            'subtitle' => 'Ordering “'.($orderingSubmission->title ?: $orderingSubmission->original_filename).'” ('
                .strtoupper((string) $orderingSubmission->language).'). Browse any sites — language does not have to match — then assign in your cart.',
            'linkAll' => true,
            'contentRoute' => route('advertiser.content-library'),
            'actions' => '<button type="button" class="btn btn-sm btn-primary" onclick="openCart()">Review cart</button>'
                .'<a href="'.e(route('advertiser.catalog', ['cancel_library_order' => 1])).'" class="btn btn-sm btn-outline-secondary">Cancel</a>'
                .'<a href="'.e(route('advertiser.content-library')).'" class="btn btn-sm btn-outline-secondary">Back to library</a>',
        ])
    @else
        @include('advertiser.partials.ordering-path', [
            'step' => 2,
            'title' => 'Catalog · Publishers',
            'subtitle' => 'One job here: pick publishers. Keep browsing with items in your cart — finish payment when ready. Prefer steps? Use Guided.',
            'linkAll' => true,
            'contentRoute' => route('advertiser.content-library'),
            'actions' => '<button type="button" class="btn btn-sm btn-outline-primary" onclick="openCart()">Open cart</button>'
                .'<a href="'.e(route('advertiser.wizard.start')).'" class="btn btn-sm btn-outline-secondary">Guided</a>',
        ])
    @endif

    @if(($approvedArticleCount ?? 0) === 0 && empty($orderingSubmission))
        <div class="alert alert-info border-0 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small mb-0">
                Each website needs its own <strong>approved</strong> article. You can still add publishers —
                readiness chips show what’s missing, and the cart checklist walks you through assignment.
            </div>
            <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-sm btn-upload">
                <i class="fa fa-upload me-1" aria-hidden="true"></i> Upload article
            </a>
        </div>
    @endif

    @php
        $catalogCart = session('cart', []);
        $catalogCartCount = (int) array_sum(array_map(fn ($row) => (int) ($row['quantity'] ?? 0), is_array($catalogCart) ? $catalogCart : []));
    @endphp
    @if($catalogCartCount > 0)
        <div class="alert alert-light border shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="small mb-0">
                You have <strong>{{ $catalogCartCount }}</strong> {{ Str::plural('site', $catalogCartCount) }} in your cart.
                Keep browsing anytime — open the cart when you are ready to assign articles and pay.
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openCart()">
                <i class="fa fa-shopping-cart me-1" aria-hidden="true"></i> Open cart
            </button>
        </div>
    @endif

    @php
        $inCatalogHideMode = (bool) (auth()->user()?->inCatalogHideMode() ?? false);
        $catalogHideUntil = auth()->user()?->catalog_hide_until;
        $catalogHideUntilLabel = ($inCatalogHideMode && $catalogHideUntil)
            ? $catalogHideUntil->timezone(config('app.timezone'))->format('M j, g:i A')
            : null;
    @endphp
    @if($inCatalogHideMode)
        <div class="alert alert-warning border-0 shadow-sm mb-3 catalog-hide-mode-banner" role="status">
            <div class="small mb-0">
                We’ve temporarily hidden listing names and website addresses on your catalog.
                You can still browse, compare metrics, and place orders as normal.
                Think this shouldn’t apply to you? Contact
                <a href="mailto:support@seolinkbuildings.com">support@seolinkbuildings.com</a>.
                @if($catalogHideUntilLabel)
                    <span class="text-muted d-block mt-1">Until {{ $catalogHideUntilLabel }}. Use the eye icon to reveal a listing’s name and URL when you need them.</span>
                @endif
            </div>
        </div>
    @endif

    @if(($catalogBonusBalance ?? 0) > 0)
        <p class="small text-muted mb-3">
            Spendable <strong>€{{ number_format((float) ($catalogSpendableBalance ?? 0), 2) }}</strong>
            (cash €{{ number_format((float) ($catalogCashBalance ?? 0), 2) }}
            + bonus €{{ number_format((float) $catalogBonusBalance, 2) }}).
            Apply bonus at checkout.
        </p>
    @endif

    @if(isset($bulkDeals) && $bulkDeals->count())
    {{-- Paged batches of 6 (not a wrapping infinite grid, not a scrollbar
         rail). Autoplay advances pages slowly; hover/focus pauses.
         Search sits beside Hide and matches the visible host / site name. --}}
    <section class="card border-0 shadow-sm mb-3 catalog-bulk-section"
             data-bulk-rail
             data-bulk-page-size="6"
             aria-labelledby="bulkDealsHeading">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="min-w-0">
                <strong id="bulkDealsHeading">
                    <i class="fa-solid fa-tags me-1 text-success" aria-hidden="true"></i>
                    Bulk discount deals
                    <span class="badge rounded-pill catalog-bulk-count">{{ $bulkDeals->count() }}</span>
                </strong>
                <div class="small text-muted">Add a 3-article pack to cart (adjust to 3–5 there) and save 10–15%. Totals at checkout include the discount.</div>
            </div>

            <div class="catalog-bulk-controls">
                <label class="catalog-bulk-search visually-hidden" for="bulkDealSearch">Search deal by site</label>
                <input type="search"
                       id="bulkDealSearch"
                       class="form-control form-control-sm catalog-bulk-search-input"
                       data-bulk-search
                       placeholder="Search deal by site"
                       autocomplete="off"
                       spellcheck="false">
                <button type="button"
                        class="btn btn-sm btn-link catalog-bulk-toggle"
                        data-bulk-toggle
                        aria-expanded="true"
                        aria-controls="bulkDealsBody">
                    <span data-bulk-toggle-label>Hide</span>
                </button>
            </div>
        </div>

        <div class="card-body" id="bulkDealsBody">
            <div class="catalog-bulk-rail"
                 id="bulkDealsRail"
                 data-bulk-track
                 tabindex="0"
                 role="group"
                 aria-label="Bulk discount deals">
                @foreach($bulkDeals as $deal)
                    @php
                        $qtyExample = (int) ($deal->bulk_pack_qty ?? 3);
                        $list = (float) ($deal->bulk_pack_list_total ?? round(((float) $deal->price) * $qtyExample, 2));
                        $after = (float) ($deal->bulk_pack_now_total ?? $list);
                        // Better-of % (custom may beat bulk) — never show a bulk badge
                        // that disagrees with the floored “now” total.
                        $pct = (float) ($deal->bulk_pack_discount_percent ?? $deal->bulk_discount_percent ?? 0);
                        $badgeKind = (string) ($deal->bulk_pack_badge_kind ?? 'bulk');
                        $pctLabel = $pct > 0
                            ? '−'.rtrim(rtrim(number_format($pct, 1), '0'), '.').'%'
                            : null;
                        // Bulk deals never follow catalog hide/mask rules —
                        // real host + listing name stay visible (limited rail).
                        // "Search deal by site" matches that same unmasked text.
                        $dealHost = $urlVisibility->host($deal->site_url);
                        $dealName = (string) $deal->site_name;
                        $dealSearch = mb_strtolower(trim($dealHost.' '.$dealName));
                    @endphp
                    <article class="bulk-deal-card"
                             data-bulk-card
                             data-bulk-deal-card
                             data-bulk-search-text="{{ $dealSearch }}">
                        <div class="bulk-deal-card__head">
                            @include('advertiser.partials.catalog-site-tile', [
                                'label' => $dealHost,
                                'size' => 'md',
                            ])
                            <span class="bulk-deal-card__host" title="{{ $dealHost }}">{{ $dealHost }}</span>
                            @if($pctLabel)
                                <span class="bulk-deal-card__pct"
                                      title="{{ $badgeKind === 'sale'
                                          ? 'Site sale applies on this pack (better than the bulk rate)'
                                          : 'Bulk discount on '.$qtyExample.'–'.(int) config('site_promotions.bulk.max_qty', 5).' articles' }}">
                                    @if($badgeKind === 'sale')
                                        Sale {{ $pctLabel }}
                                    @else
                                        {{ $pctLabel }}
                                    @endif
                                </span>
                            @endif
                        </div>

                        <div class="bulk-deal-card__metrics">
                            <span>DR <strong>{{ $deal->dr }}</strong></span>
                            <span>DA <strong>{{ $deal->da }}</strong></span>
                        </div>

                        <div class="bulk-deal-card__price">
                            <span class="bulk-deal-card__was">€{{ number_format($list, 2) }}</span>
                            <strong class="bulk-deal-card__now">€{{ number_format($after, 2) }}</strong>
                            <span class="bulk-deal-card__qty">for {{ $qtyExample }}</span>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-primary buy-now bulk-deal-card__cta"
                                data-id="{{ $deal->id }}"
                                data-base-price="{{ $deal->price }}"
                                data-publisher-price="{{ $deal->original_price ?? $deal->price }}"
                                data-name="{{ $dealName }}"
                                data-bulk-hint="1"
                                data-bulk-qty="{{ $qtyExample }}"
                                aria-label="Add {{ $dealHost }} 3-article pack to cart">
                            Add 3 to cart
                        </button>
                    </article>
                @endforeach
            </div>

            <p class="catalog-bulk-empty small text-muted mb-0" data-bulk-empty hidden role="status">
                No bulk deal for this site.
            </p>

            {{-- Centered under the deals: batch pager (6 per page). --}}
            <nav class="catalog-bulk-pager"
                 data-bulk-pager
                 aria-label="Bulk deal pages">
                <button type="button"
                        class="catalog-bulk-nav"
                        data-bulk-scroll="prev"
                        aria-controls="bulkDealsRail"
                        aria-label="Previous bulk deals page">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                </button>
                <div class="catalog-bulk-pages" data-bulk-pages role="group" aria-label="Page numbers"></div>
                <button type="button"
                        class="catalog-bulk-nav"
                        data-bulk-scroll="next"
                        aria-controls="bulkDealsRail"
                        aria-label="Next bulk deals page">
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </button>
                <p class="catalog-bulk-page-label mb-0" data-bulk-page-label>Page 1 of 1</p>
            </nav>
        </div>
    </section>
    @endif


    <!-- HEADER -->
    <div class="row mb-3">
        <div class="col-md-12">
            <h2 class="mb-1 fw-semibold">Catalog</h2>
            <p class="text-muted mb-0">
                @if(!empty($orderingSubmission))
                    Browse any verified publishers for “{{ $orderingSubmission->title ?: $orderingSubmission->original_filename }}”. Filters stay optional — language does not have to match.
                @else
                    Browse verified publishers and add sites to your cart.
                @endif
            </p>
        </div>
    </div>

    <!-- FILTERS SECTION -->
@php
    $moreFilterKeys = ['sponsored','favorites_filter','blacklist_filter','da_min','da_max','dr_min','dr_max','traffic_min','traffic_max','new_badge'];
    $moreFiltersOpen = collect($moreFilterKeys)->contains(fn ($k) => filled(request($k)));
    // Each chip carries the query keys it owns so it can be dismissed on its own.
    // Range filters span two inputs, so one chip clears both ends.
    $activeFilterChips = [];
    if (request('site')) $activeFilterChips[] = ['label' => 'Recommended site', 'key' => 'site', 'params' => ['site']];
    if (request('search')) $activeFilterChips[] = ['label' => 'Search: '.request('search'), 'key' => 'search', 'params' => ['search']];
    if (request('category')) $activeFilterChips[] = ['label' => 'Category', 'key' => 'category', 'params' => ['category']];
    if (request('country')) $activeFilterChips[] = ['label' => 'Country', 'key' => 'country', 'params' => ['country']];
    if (request('price_min') || request('price_max')) $activeFilterChips[] = ['label' => 'Price', 'key' => 'price', 'params' => ['price_min', 'price_max']];
    if (request('language')) $activeFilterChips[] = ['label' => 'Language', 'key' => 'language', 'params' => ['language']];
    if (request('sponsored') == '1') $activeFilterChips[] = ['label' => 'Sponsored', 'key' => 'sponsored', 'params' => ['sponsored']];
    if (request('favorites_filter') == '1') $activeFilterChips[] = ['label' => 'Favorites', 'key' => 'favorites_filter', 'params' => ['favorites_filter']];
    if (request('blacklist_filter') == '1') $activeFilterChips[] = ['label' => 'Blacklist', 'key' => 'blacklist_filter', 'params' => ['blacklist_filter']];
    if (request('da_min') || request('da_max')) $activeFilterChips[] = ['label' => 'DA (Domain Authority)', 'key' => 'da', 'params' => ['da_min', 'da_max']];
    if (request('dr_min') || request('dr_max')) $activeFilterChips[] = ['label' => 'DR (Domain Rating)', 'key' => 'dr', 'params' => ['dr_min', 'dr_max']];
    if (request('traffic_min') || request('traffic_max')) $activeFilterChips[] = ['label' => 'Traffic', 'key' => 'traffic', 'params' => ['traffic_min', 'traffic_max']];
    if (request('new_badge') == '1') $activeFilterChips[] = ['label' => 'New sites', 'key' => 'new_badge', 'params' => ['new_badge']];
    $inventoryTotal = $sites->total();
    $inventoryFrom = $sites->getCollection()->min(fn ($s) => (float) $s->price);
@endphp

{{-- Result-first teaser (CV2): inventory + price under the Catalog title.
     Filters live just above the results table (no Hide/Show toggle). --}}
<div class="catalog-inventory-teaser d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="small">
        @if($inventoryTotal > 0)
            <strong class="text-dark">{{ number_format($inventoryTotal) }}</strong>
            {{ Str::plural('placement', $inventoryTotal) }} available
            @if($inventoryFrom !== null)
                · from <strong class="catalog-inventory-teaser__price">€{{ number_format($inventoryFrom, 2) }}</strong>
            @endif
        @else
            <span class="text-muted">No placements match yet — broaden filters below</span>
        @endif
    </div>
</div>



<!-- CONTENT AREA -->
    <div class="row">
        <div class="col-md-12">

            @php
                $resultTotal = $sites->total();
                $hasActiveFilters = count($activeFilterChips) > 0;
                $sortValue = request('sort', 'dr_desc');
                $catalogFilterStatus = app(\App\Services\Catalog\CatalogFilterStatus::class);
                $catalogResultsCopy = $catalogFilterStatus->summarize(
                    request(),
                    $resultTotal,
                    $sites->firstItem(),
                    $sites->lastItem()
                );
                $catalogEmptyRecovery = ($resultTotal < 1 && $hasActiveFilters)
                    ? $catalogFilterStatus->emptyRecovery(request())
                    : null;
            @endphp

            {{-- Filters + sort + suggest sit immediately above the results table. --}}
            <div class="row mb-3" id="catalogFiltersPanel">
                <div class="col-md-12">
                    <div class="card border-0 shadow-sm catalog-filters-card">
                        <div class="card-body py-3">
                <form method="GET" action="{{ route('advertiser.catalog') }}" id="filterForm">
                    <div class="row g-2 g-md-3 align-items-start">
                        <!-- Primary: Search (site + category/country/language text) -->
                        <div class="col-12 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Search</label>
                            <div class="catalog-search-typeahead" data-catalog-typeahead>
                                <input type="search"
                                       name="search"
                                       id="catalogSearchInput"
                                       class="form-control form-control-sm"
                                       placeholder="{{ $inCatalogHideMode
                                           ? 'Name, domain, category… (rows stay masked)'
                                           : 'Name, domain, category… or da>40 / price<100' }}"
                                       title="{{ $inCatalogHideMode
                                           ? 'Suggestions appear as you type. Press Enter or Apply for full results. Matching rows stay masked until you use the eye.'
                                           : 'Suggestions appear as you type. Press Enter or Apply for full filtered results. Metric tokens (da>40, price<100) apply on full search.' }}"
                                       value="{{ request('search') }}"
                                       autocomplete="off"
                                       enterkeyhint="search"
                                       role="combobox"
                                       aria-autocomplete="list"
                                       aria-expanded="false"
                                       aria-controls="catalogSuggestList"
                                       aria-describedby="catalogSearchStatus">
                                <ul id="catalogSuggestList"
                                    class="catalog-suggest-list"
                                    role="listbox"
                                    hidden></ul>
                                <span id="catalogSearchStatus" class="visually-hidden" role="status" aria-live="polite"></span>
                            </div>
                        </div>

                        <!-- Primary: Category (searchable dropdown) -->
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Category</label>
                            <div class="multi-select-wrapper" data-multi-select="category">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('categoryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCategoriesDisplay" data-placeholder="All categories" data-singular="category" data-plural="categories">
                                        <span class="placeholder-text">All categories</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="categoryMultiDropdown" role="listbox" aria-multiselectable="true">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="categorySearch" class="form-control form-control-sm" aria-label="Search categories" placeholder="Type to search categories…" onkeyup="filterMultiOptions('categoryMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    <div class="options-list" id="categoryMultiOptions">
                                        @foreach($siteCategories as $category)
                                            <label class="option-item" role="option" aria-selected="false" tabindex="-1">
                                                <input type="checkbox" value="{{ $category }}" data-type="category" data-name="{{ $category }}" onchange="updateMultiFilter(this)" tabindex="-1">
                                                <span>{{ $category }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none">No categories found</div>
                                </div>
                            </div>
                            <input type="hidden" name="category" id="selectedCategory" value="{{ request('category') }}">
                        </div>

                        <!-- Primary: Country (searchable dropdown) -->
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Country</label>
                            <div class="multi-select-wrapper" data-multi-select="country">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('countryMultiDropdown', this)">
                                    <div class="selected-items" id="selectedCountriesDisplay" data-placeholder="All countries" data-singular="country" data-plural="countries">
                                        <span class="placeholder-text">All countries</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="countryMultiDropdown" role="listbox" aria-multiselectable="true">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="countrySearch" class="form-control form-control-sm" aria-label="Search countries" placeholder="Type to search countries…" onkeyup="filterMultiOptions('countryMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    @if(!empty($countryPickerGroups))
                                        <div class="multi-select-group-actions" onclick="event.stopPropagation()">
                                            @foreach($countryPickerGroups as $group)
                                                <button type="button"
                                                        class="btn btn-link btn-sm multi-select-group-action"
                                                        data-country-group="{{ $group['key'] }}"
                                                        data-country-codes="{{ implode(',', $group['codes']) }}"
                                                        data-country-group-label="{{ $group['label'] }}"
                                                        aria-pressed="false"
                                                        title="Browse {{ $group['label'] }} markets">
                                                    {{ $group['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="options-list" id="countryMultiOptions">
                                        @foreach(($countryPickerSections ?? []) as $section)
                                            <div class="multi-select-section{{ ($section['key'] ?? '') === 'recent' ? ' is-empty' : '' }}"
                                                 data-section="{{ $section['key'] }}"
                                                 @if(($section['key'] ?? '') === 'recent') hidden @endif>
                                                <div class="multi-select-section__label" role="presentation">{{ $section['label'] }}</div>
                                                @foreach(($section['options'] ?? []) as $option)
                                                    <label class="option-item" role="option" aria-selected="false" tabindex="-1">
                                                        <input type="checkbox"
                                                               value="{{ $option['code'] }}"
                                                               data-type="country"
                                                               data-name="{{ $option['name'] }}"
                                                               data-count="{{ (int) $option['count'] }}"
                                                               onchange="updateMultiFilter(this)"
                                                               tabindex="-1">
                                                        <span>{{ $option['name'] }} ({{ number_format((int) $option['count']) }})</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endforeach
                                        @if(empty($countryPickerSections) || collect($countryPickerSections)->every(fn ($s) => ($s['key'] ?? '') === 'recent' || empty($s['options'])))
                                            <div class="multi-select-section" data-section="empty-inventory">
                                                <div class="text-muted small px-2 py-1">No markets with listings yet</div>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="multi-select-empty d-none">No countries found</div>
                                </div>
                            </div>
                            <input type="hidden" name="country" id="selectedCountry" value="{{ request('country') }}">
                        </div>

                        <!-- Primary: Language (searchable dropdown) -->
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Language</label>
                            <div class="multi-select-wrapper" data-multi-select="language">
                                <div class="multi-select-input form-control form-control-sm" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" onclick="toggleMultiDropdown('languageMultiDropdown', this)">
                                    <div class="selected-items" id="selectedLanguagesDisplay" data-placeholder="All languages" data-singular="language" data-plural="languages">
                                        <span class="placeholder-text">All languages</span>
                                    </div>
                                    <i class="fa fa-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="multi-select-dropdown" id="languageMultiDropdown" role="listbox" aria-multiselectable="true">
                                    <div class="search-box" onclick="event.stopPropagation()">
                                        <i class="fa fa-search" aria-hidden="true"></i>
                                        <input type="text" id="languageSearch" class="form-control form-control-sm" aria-label="Search languages" placeholder="Type to search languages…" onkeyup="filterMultiOptions('languageMultiOptions', this.value)" autocomplete="off">
                                    </div>
                                    <div class="options-list" id="languageMultiOptions">
                                        @foreach($availableLanguages as $code => $name)
                                            <label class="option-item" role="option" aria-selected="false" tabindex="-1">
                                                <input type="checkbox" value="{{ $code }}" data-type="language" data-name="{{ $name }}" onchange="updateMultiFilter(this)" tabindex="-1">
                                                <span>{{ $name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none">No languages found</div>
                                </div>
                            </div>
                            <input type="hidden" name="language" id="selectedLanguage" value="{{ request('language') }}">
                        </div>

                        <!-- Primary: Price -->
                        <div class="col-6 col-sm-6 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1">Price (€)</label>
                            <div class="d-flex gap-2">
                                <input type="number"
                                       name="price_min"
                                       id="priceMinInput" aria-label="Minimum price in euros"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Min"
                                       min="0" step="0.01"
                                       value="{{ request('price_min') }}">
                                <input type="number"
                                       name="price_max"
                                       id="priceMaxInput" aria-label="Maximum price in euros"
                                       class="form-control form-control-sm no-spinner"
                                       placeholder="Max"
                                       min="0" step="0.01"
                                       value="{{ request('price_max') }}">
                            </div>
                            <div class="filter-presets" data-preset-group="price">
                                <button type="button" class="filter-preset" data-min="" data-max="50" data-target-min="priceMinInput" data-target-max="priceMaxInput">Under €50</button>
                                <button type="button" class="filter-preset" data-min="50" data-max="150" data-target-min="priceMinInput" data-target-max="priceMaxInput">€50–150</button>
                                <button type="button" class="filter-preset" data-min="150" data-max="" data-target-min="priceMinInput" data-target-max="priceMaxInput">€150+</button>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="col-12 col-lg-2">
                            <label class="form-label fw-semibold small text-muted mb-1 d-none d-md-block">&nbsp;</label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-sm btn-primary px-3" id="applyFiltersBtn">
                                    <i class="fa-solid fa-filter me-1" aria-hidden="true"></i> Filter
                                </button>
                                <button type="button" class="btn btn-sm btn-cta-secondary px-2" id="toggleMoreFiltersBtn" aria-controls="moreFiltersDrawer" aria-expanded="{{ $moreFiltersOpen ? 'true' : 'false' }}">
                                    More
                                    @if($moreFiltersOpen)
                                        <span class="badge rounded-pill ms-1" data-more-filters-count
                                              style="background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#1a585e);border:1px solid var(--brand-primary-border,#b8e4e4);">{{ collect($moreFilterKeys)->filter(fn($k) => filled(request($k)))->count() }}</span>
                                    @endif
                                </button>
                                <a href="{{ route('advertiser.catalog') }}"
                                   class="btn btn-sm btn-cta-tertiary px-1 catalog-reset-filters"
                                   id="catalogResetFilters">
                                    Reset
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- More filters drawer -->
                    <div id="moreFiltersDrawer" class="mt-3 pt-3 border-top" style="{{ $moreFiltersOpen ? '' : 'display:none;' }}">
                        <div class="row g-3 align-items-end">
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Sponsored</label>
                                <select name="sponsored" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('sponsored') == '1' ? 'selected' : '' }}>Sponsored Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Favorites</label>
                                <select name="favorites_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('favorites_filter') == '1' ? 'selected' : '' }}>Favorites Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Blacklist</label>
                                <select name="blacklist_filter" class="form-select form-select-sm">
                                    <option value="">All Sites</option>
                                    <option value="1" {{ request('blacklist_filter') == '1' ? 'selected' : '' }}>Blacklisted Only</option>
                                </select>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Moz Domain Authority — site strength score from 0–100">DA</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="da_min" id="daMinInput" aria-label="Minimum Domain Authority" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('da_min') }}">
                                    <input type="number" name="da_max" id="daMaxInput" aria-label="Maximum Domain Authority" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('da_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="da">
                                    <button type="button" class="filter-preset" data-min="20" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 20+</button>
                                    <button type="button" class="filter-preset" data-min="40" data-max="" data-target-min="daMinInput" data-target-max="daMaxInput">DA 40+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                </label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="dr_min" id="drMinInput" aria-label="Minimum Domain Rating" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" step="1" value="{{ request('dr_min') }}">
                                    <input type="number" name="dr_max" id="drMaxInput" aria-label="Maximum Domain Rating" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" step="1" value="{{ request('dr_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="dr">
                                    <button type="button" class="filter-preset" data-min="30" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 30+</button>
                                    <button type="button" class="filter-preset" data-min="50" data-max="" data-target-min="drMinInput" data-target-max="drMaxInput">DR 50+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">Monthly Traffic</label>
                                <div class="d-flex gap-2">
                                    <input type="number" name="traffic_min" id="trafficMinInput" aria-label="Minimum monthly traffic" class="form-control form-control-sm no-spinner" placeholder="Min" min="0" max="4294967295" step="1" inputmode="numeric" value="{{ request('traffic_min') }}">
                                    <input type="number" name="traffic_max" id="trafficMaxInput" aria-label="Maximum monthly traffic" class="form-control form-control-sm no-spinner" placeholder="Max" min="0" max="4294967295" step="1" inputmode="numeric" value="{{ request('traffic_max') }}">
                                </div>
                                <div class="filter-presets" data-preset-group="traffic">
                                    <button type="button" class="filter-preset" data-min="10000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">10k+</button>
                                    <button type="button" class="filter-preset" data-min="50000" data-max="" data-target-min="trafficMinInput" data-target-max="trafficMaxInput">50k+</button>
                                </div>
                            </div>

                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label fw-semibold small text-muted mb-1">New Sites</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="new_badge" id="new_badge" value="1" {{ request('new_badge') == 1 ? 'checked' : '' }}>
                                    <label class="form-check-label" for="new_badge">Show New Sites</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <div id="catalogActiveFiltersHost">
                @if(count($activeFilterChips))
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3" id="activeFilterChips">
                        <span class="small text-muted me-1">Active:</span>
                        @foreach($activeFilterChips as $chip)
                            @php
                                // Drop only this chip's own keys; page resets so the
                                // narrower result set does not land on an empty page.
                                // Allowlisted via CatalogUrlQuery so chip links match
                                // live / refresh URLs (same source of truth).
                                $chipRemoveUrl = route(
                                    'advertiser.catalog',
                                    \App\Services\Catalog\CatalogUrlQuery::except(
                                        request()->query(),
                                        $chip['params']
                                    )
                                );
                            @endphp
                            <span class="badge rounded-pill filter-chip">
                                {{ $chip['label'] }}
                                <a href="{{ $chipRemoveUrl }}"
                                   class="filter-chip__remove"
                                   aria-label="Remove filter: {{ $chip['label'] }}"
                                   title="Remove this filter">&times;</a>
                            </span>
                        @endforeach
                        <a href="{{ route('advertiser.catalog') }}" class="small ms-1 catalog-clear-all">Clear all</a>
                    </div>
                @endif
                </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="catalog-results-bar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="text-muted small" id="catalogResultsCount" data-catalog-results-count>
                    {{ $catalogResultsCopy['text'] }}
                </div>
                <div id="catalogLiveStatus" class="visually-hidden" aria-live="polite" aria-atomic="true">{{ $catalogResultsCopy['announce'] }}</div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label for="catalogSort" class="small text-muted mb-0">Sort</label>
                    <select id="catalogSort"
                            name="sort"
                            form="filterForm"
                            class="form-select form-select-sm catalog-sort-select">
                        <option value="dr_desc" @selected($sortValue === 'dr_desc')>DR (high → low)</option>
                        <option value="da_desc" @selected($sortValue === 'da_desc')>DA (high → low)</option>
                        <option value="traffic_desc" @selected($sortValue === 'traffic_desc')>Traffic (high → low)</option>
                        <option value="price_asc" @selected($sortValue === 'price_asc')>Price (low → high)</option>
                        <option value="price_desc" @selected($sortValue === 'price_desc')>Price (high → low)</option>
                        <option value="newest" @selected($sortValue === 'newest')>Newest first</option>
                    </select>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <p class="small text-muted mb-0">
                    Searching for a site that isn’t listed yet?
                </p>
                <button type="button" class="btn btn-sm btn-outline-success btn-suggest-website"
                        data-search="{{ request('search') }}">
                    <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
                </button>
            </div>

            @if(isset($bulkDeals) && $bulkDeals->count())
            {{-- One row that scrolls sideways, not a grid that wraps.
                 As a grid, twelve deals stacked into three rows of cards and
                 pushed the results table most of a screen down — the section
                 grew with the offer count and the catalog paid for it. A rail
                 is the same height whether there are two deals or twenty. --}}
            <section class="card border-0 shadow-sm mb-3 catalog-bulk-section"
                     data-bulk-rail
                     aria-labelledby="bulkDealsHeading">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <strong id="bulkDealsHeading">
                            <i class="fa-solid fa-tags me-1 text-success" aria-hidden="true"></i>
                            Bulk discount deals
                            <span class="badge rounded-pill catalog-bulk-count">{{ $bulkDeals->count() }}</span>
                        </strong>
                        <div class="small text-muted">Add a 3-article pack to cart (adjust to 3–5 there) and save 10–15%. Totals at checkout include the discount.</div>
                    </div>

                    <div class="catalog-bulk-controls">
                        <button type="button"
                                class="catalog-bulk-nav"
                                data-bulk-scroll="prev"
                                aria-controls="bulkDealsRail"
                                aria-label="Show previous bulk deals">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="catalog-bulk-nav"
                                data-bulk-scroll="next"
                                aria-controls="bulkDealsRail"
                                aria-label="Show more bulk deals">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-link catalog-bulk-toggle"
                                data-bulk-toggle
                                aria-expanded="true"
                                aria-controls="bulkDealsBody">
                            <span data-bulk-toggle-label>Hide</span>
                        </button>
                    </div>
                </div>

                <div class="card-body" id="bulkDealsBody">
                    <div class="catalog-bulk-rail"
                         id="bulkDealsRail"
                         data-bulk-track
                         tabindex="0"
                         role="group"
                         aria-label="Bulk discount deals, scrollable">
                        @foreach($bulkDeals as $deal)
                            @php
                                $qtyExample = (int) ($deal->bulk_pack_qty ?? 3);
                                $list = (float) ($deal->bulk_pack_list_total ?? round(((float) $deal->price) * $qtyExample, 2));
                                $after = (float) ($deal->bulk_pack_now_total ?? $list);
                                // Better-of % (custom may beat bulk) — never show a bulk badge
                                // that disagrees with the floored “now” total.
                                $pct = (float) ($deal->bulk_pack_discount_percent ?? $deal->bulk_discount_percent ?? 0);
                                $badgeKind = (string) ($deal->bulk_pack_badge_kind ?? 'bulk');
                                $pctLabel = $pct > 0
                                    ? '−'.rtrim(rtrim(number_format($pct, 1), '0'), '.').'%'
                                    : null;
                                // Bulk deals never follow catalog hide/mask rules —
                                // real host + listing name stay visible (limited rail).
                                $dealHost = $urlVisibility->host($deal->site_url);
                                $dealName = (string) $deal->site_name;
                            @endphp
                            <article class="bulk-deal-card" data-bulk-deal-card>
                                <div class="bulk-deal-card__head">
                                    @include('advertiser.partials.catalog-site-tile', [
                                        'label' => $dealHost,
                                        'size' => 'md',
                                    ])
                                    <span class="bulk-deal-card__host" title="{{ $dealHost }}">{{ $dealHost }}</span>
                                    @if($pctLabel)
                                        <span class="bulk-deal-card__pct"
                                              title="{{ $badgeKind === 'sale'
                                                  ? 'Site sale applies on this pack (better than the bulk rate)'
                                                  : 'Bulk discount on '.$qtyExample.'–'.(int) config('site_promotions.bulk.max_qty', 5).' articles' }}">
                                            @if($badgeKind === 'sale')
                                                Sale {{ $pctLabel }}
                                            @else
                                                {{ $pctLabel }}
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                <div class="bulk-deal-card__metrics">
                                    <span>DR <strong>{{ $deal->dr }}</strong></span>
                                    <span>DA <strong>{{ $deal->da }}</strong></span>
                                </div>

                                <div class="bulk-deal-card__price">
                                    <span class="bulk-deal-card__was">€{{ number_format($list, 2) }}</span>
                                    <strong class="bulk-deal-card__now">€{{ number_format($after, 2) }}</strong>
                                    <span class="bulk-deal-card__qty">for {{ $qtyExample }}</span>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-primary buy-now bulk-deal-card__cta"
                                        data-id="{{ $deal->id }}"
                                        data-base-price="{{ $deal->price }}"
                                        data-publisher-price="{{ $deal->original_price ?? $deal->price }}"
                                        data-name="{{ $dealName }}"
                                        data-bulk-hint="1"
                                        data-bulk-qty="{{ $qtyExample }}"
                                        aria-label="Add {{ $dealHost }} 3-article pack to cart">
                                    Add 3 to cart
                                </button>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            <!-- Publishers Table -->
            {{-- Sorting and paging are full reloads. Without this the click looked
                 dead for as long as the request took. --}}
            @include('advertiser.partials.catalog-results')
    
        </div>
    </div>

</div>

<script>
window.CatalogConfig = {
    favorites: @json($favorites ?? []),
    blacklist: @json($blacklist ?? []),
    categoryParam: @json((string) request('category', '')),
    countryParam: @json((string) request('country', '')),
    languageParam: @json((string) request('language', '')),
    countryGroups: @json(collect($countryPickerGroups ?? [])->mapWithKeys(fn ($g) => [$g['key'] => $g['codes']])->all()),
    countryGroupLabels: @json(collect($countryPickerGroups ?? [])->mapWithKeys(fn ($g) => [$g['key'] => $g['label']])->all()),
    favoritesFilter: @json(request('favorites_filter') == '1'),
    blacklistFilter: @json(request('blacklist_filter') == '1'),
    csrfToken: @json(csrf_token()),
    contactEmail: @json(auth()->user()->email ?? ''),
    inCatalogHideMode: @json(auth()->user()?->inCatalogHideMode() ?? false),
    catalogHideUntil: @json(optional(auth()->user()?->catalog_hide_until)->toIso8601String()),
    // URL is the source of truth for listing state (Phase 2).
    catalogPath: @json(parse_url(route('advertiser.catalog'), PHP_URL_PATH)),
    queryKeys: @json(\App\Services\Catalog\CatalogUrlQuery::KEYS),
    defaultSort: @json(\App\Services\Catalog\CatalogUrlQuery::DEFAULT_SORT),
    // Phase 7 kill switch — false falls back to full page navigations.
    liveSearch: @json((bool) config('catalog.live_search.enabled', true)),
    routes: {
        results: @json(route('advertiser.catalog.results')),
        favoritesSave: @json(route('advertiser.favorites.save')),
        blacklistSave: @json(route('advertiser.blacklist.save')),
        websiteSuggestionsStore: @json(route('advertiser.website-suggestions.store')),
        siteClaim: @json(route('advertiser.sites.claim')),
        revealUrl: @json(route('advertiser.catalog.reveal-url', ['site' => '__SITE__'])),
        hideUrl: @json(route('advertiser.catalog.hide-url', ['site' => '__SITE__'])),
        copyTrack: @json(route('advertiser.catalog.copy-track')),
        suggest: @json(route('advertiser.catalog.suggest')),
        catalog: @json(route('advertiser.catalog'))
    }
};
</script>
<script src="{{ asset('assets/js/catalog.js') }}?v={{ @filemtime(public_path('assets/js/catalog.js')) ?: '1' }}" defer></script>

@endsection
