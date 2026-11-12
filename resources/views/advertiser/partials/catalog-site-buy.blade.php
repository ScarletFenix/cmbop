{{-- Buy CTAs for one catalog row/card. On the link-insertion tab the primary
     action is the insertion product (advertiser price only). Guest-post tab
     keeps Add to cart plus a compact text link for insertions. --}}
@php
    $buyAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
    $isLinkInsertionCatalog = $isLinkInsertionCatalog ?? false;
    $showLiPrimary = $isLinkInsertionCatalog && ! empty($offersLinkInsertion) && ($linkInsertionListPrice ?? 0) > 0;
    $liAlsoHref = route(
        'advertiser.catalog',
        \App\Services\Catalog\CatalogUrlQuery::canonicalize(array_merge(
            \App\Services\Catalog\CatalogUrlQuery::fromRequest(request()),
            ['product' => \App\Support\LinkInsertionProduct::TYPE, 'site' => (string) $site->id]
        ))
    );
@endphp
@if($isOwnedByMe)
    @include('advertiser.partials.catalog-own-listing', ['align' => $buyAlign])
@elseif($showLiPrimary)
    <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2{{ $inLinkInsertionCart ? ' is-in-cart' : '' }}"
            data-id="{{ $site->id }}"
            data-base-price="{{ $linkInsertionListPrice }}"
            data-name="{{ $displayName }}"
            data-product-type="link_insertion"
            @if($inLinkInsertionCart) data-in-cart="1" @endif
            aria-label="{{ $inLinkInsertionCart ? 'In cart — open cart, '.$identityLabel.' is already in your cart' : 'Add to cart for '.$identityLabel }}">
        @if($inLinkInsertionCart)
            <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
            <span>In cart</span>
        @else
            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
            <span>{{ __('messages.catalog_preview_add_to_cart') }}</span>
        @endif
    </button>
    <div class="catalog-buy-addon-hint small text-muted mt-1" data-site-id="{{ $site->id }}" hidden></div>
@else
    <button type="button" class="btn btn-sm btn-primary buy-now d-inline-flex justify-content-center align-items-center gap-2{{ $inCart ? ' is-in-cart' : '' }}"
            data-id="{{ $site->id }}"
            data-base-price="{{ $catalogListPrice }}"
            data-publisher-price="{{ $catalogPublisherPrice }}"
            data-discount-percent="{{ $catalogSalePct ?? 0 }}"
            data-name="{{ $displayName }}"
            data-product-type="guest_post"
            @if($inCart) data-in-cart="1" @endif
            aria-label="{{ $inCart ? 'In cart — open cart, '.$identityLabel.' is already in your cart' : 'Add to cart for '.$identityLabel }}">
        @if($inCart)
            <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
            <span>In cart</span>
        @else
            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i>
            <span>Add to cart</span>
        @endif
    </button>
    @if($offersLinkInsertion && $linkInsertionListPrice > 0)
        @if($inLinkInsertionCart)
            <a href="{{ $liAlsoHref }}"
               class="catalog-li-also buy-now{{ $inLinkInsertionCart ? ' is-in-cart' : '' }}"
               data-id="{{ $site->id }}"
               data-base-price="{{ $linkInsertionListPrice }}"
               data-name="{{ $displayName }}"
               data-product-type="link_insertion"
               data-in-cart="1"
               aria-label="{{ __('messages.catalog_li_also_in_cart') }}">
                {{ __('messages.catalog_li_also_in_cart') }}
            </a>
        @else
            <a href="{{ $liAlsoHref }}"
               class="catalog-li-also buy-now"
               data-id="{{ $site->id }}"
               data-base-price="{{ $linkInsertionListPrice }}"
               data-name="{{ $displayName }}"
               data-product-type="link_insertion"
               aria-label="{{ __('messages.catalog_li_also_available', ['price' => format_money($linkInsertionListPrice)]) }}">
                {{ __('messages.catalog_li_also_available', ['price' => format_money($linkInsertionListPrice)]) }}
            </a>
        @endif
    @endif
    <div class="catalog-buy-addon-hint small text-muted mt-1" data-site-id="{{ $site->id }}" hidden></div>
@endif
