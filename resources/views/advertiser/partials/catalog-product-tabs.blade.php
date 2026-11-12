{{-- Product tab row: Guest posts | Link insertions | Banners (coming soon).
     $context = catalog (advertiser listing) | marketplace (public marketing). --}}
@php
    use App\Support\LinkInsertionProduct;
    use App\Services\Catalog\CatalogUrlQuery;

    $context = $context ?? 'catalog';
    $active = LinkInsertionProduct::catalogTab($activeProduct ?? request('product'));
    $isCatalog = $context === 'catalog';

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
            {{ __('messages.catalog_tab_guest_posts') }}
        </a>
        <a href="{{ $liHref }}"
           class="catalog-product-tabs__tab{{ $active === LinkInsertionProduct::TYPE ? ' is-active' : '' }}"
           role="tab"
           data-catalog-product="{{ LinkInsertionProduct::TYPE }}"
           aria-selected="{{ $active === LinkInsertionProduct::TYPE ? 'true' : 'false' }}"
           @if($active === LinkInsertionProduct::TYPE) aria-current="page" @endif>
            {{ __('messages.catalog_tab_link_insertions') }}
            <span class="catalog-product-tabs__from">{{ LinkInsertionProduct::marketingFromCopy() }}</span>
        </a>
        <span class="catalog-product-tabs__tab is-disabled"
              role="tab"
              aria-selected="false"
              aria-disabled="true">
            {{ __('messages.catalog_tab_banners') }}
            <span class="catalog-product-tabs__soon">{{ __('messages.catalog_tab_coming_soon') }}</span>
        </span>
    </div>
</nav>
