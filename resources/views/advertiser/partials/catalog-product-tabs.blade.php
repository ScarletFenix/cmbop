{{-- Product tab row: Guest posts | Link insertions | Banners (coming soon).
     $context = catalog (advertiser listing) | marketplace (public marketing). --}}
@php
    use App\Support\LinkInsertionProduct;
    use App\Services\Catalog\CatalogUrlQuery;

    $context = $context ?? 'catalog';
    $active = LinkInsertionProduct::catalogTab($activeProduct ?? request('product'));
    $isCatalog = $context === 'catalog';
    $tabCounts = is_array($catalogTabCounts ?? null) ? $catalogTabCounts : [];
    $guestCount = array_key_exists('guest_post', $tabCounts) ? (int) $tabCounts['guest_post'] : null;
    $liCount = array_key_exists('link_insertion', $tabCounts) ? (int) $tabCounts['link_insertion'] : null;

    if ($isCatalog) {
        $baseQuery = CatalogUrlQuery::fromRequest(request());
        unset($baseQuery['page']);
        $guestHref = route('advertiser.catalog', CatalogUrlQuery::canonicalize(array_merge($baseQuery, ['product' => LinkInsertionProduct::GUEST_POST])));
        $liHref = route('advertiser.catalog', CatalogUrlQuery::canonicalize(array_merge($baseQuery, ['product' => LinkInsertionProduct::TYPE])));
    } else {
        $marketQuery = array_filter([
            'search' => trim((string) ($catalogSearch ?? request('search', ''))),
        ], static fn ($value) => $value !== null && $value !== '');
        $guestHref = localized_url('marketplace').($marketQuery !== [] ? '?'.http_build_query($marketQuery) : '');
        $liHref = localized_url('link-insertion');
    }
@endphp
<nav class="catalog-product-tabs" aria-label="{{ __('messages.catalog_tab_aria') }}">
    <div class="catalog-product-tabs__scroller" role="tablist">
        <a href="{{ $guestHref }}"
           class="catalog-product-tabs__tab{{ $active === LinkInsertionProduct::GUEST_POST ? ' is-active' : '' }}"
           role="tab"
           data-catalog-product="{{ LinkInsertionProduct::GUEST_POST }}"
           aria-selected="{{ $active === LinkInsertionProduct::GUEST_POST ? 'true' : 'false' }}"
           @if($active === LinkInsertionProduct::GUEST_POST) aria-current="page" @endif>
            <span class="catalog-product-tabs__label catalog-product-tabs__label--full">{{ __('messages.catalog_tab_guest_posts') }}</span>
            <span class="catalog-product-tabs__label catalog-product-tabs__label--short">{{ __('messages.catalog_tab_guest_posts_short') }}</span>
            @if($guestCount !== null)
                <span class="catalog-product-tabs__count" data-catalog-tab-count="guest_post">{{ number_format($guestCount) }}</span>
            @endif
        </a>
        <a href="{{ $liHref }}"
           class="catalog-product-tabs__tab{{ $active === LinkInsertionProduct::TYPE ? ' is-active' : '' }}"
           role="tab"
           data-catalog-product="{{ LinkInsertionProduct::TYPE }}"
           aria-selected="{{ $active === LinkInsertionProduct::TYPE ? 'true' : 'false' }}"
           @if($active === LinkInsertionProduct::TYPE) aria-current="page" @endif>
            <span class="catalog-product-tabs__label catalog-product-tabs__label--full">{{ __('messages.catalog_tab_link_insertions') }}</span>
            <span class="catalog-product-tabs__label catalog-product-tabs__label--short">{{ __('messages.catalog_tab_link_insertions_short') }}</span>
            @if($liCount !== null)
                <span class="catalog-product-tabs__count" data-catalog-tab-count="link_insertion">{{ number_format($liCount) }}</span>
            @endif
        </a>
        <span class="catalog-product-tabs__tab is-disabled"
              role="tab"
              aria-selected="false"
              aria-disabled="true"
              title="{{ __('messages.catalog_tab_coming_soon_tip') }}"
              data-glass-tip
              data-glass-tip-title="{{ __('messages.catalog_tab_coming_soon') }}"
              data-glass-tip-body="{{ __('messages.catalog_tab_coming_soon_tip') }}"
              data-glass-tip-placement="bottom">
            <span class="catalog-product-tabs__label catalog-product-tabs__label--full">{{ __('messages.catalog_tab_banners') }}</span>
            <span class="catalog-product-tabs__label catalog-product-tabs__label--short">{{ __('messages.catalog_tab_banners_short') }}</span>
            <span class="catalog-product-tabs__soon">{{ __('messages.catalog_tab_coming_soon') }}</span>
        </span>
    </div>
</nav>
@if($isCatalog)
    <p class="catalog-product-tabs__explainer"
       data-catalog-li-explainer
       @if($active !== LinkInsertionProduct::TYPE) hidden @endif>
        {{ __('messages.catalog_tab_explainer_link_insertions') }}
    </p>
@endif
