{{-- Buy CTAs for one catalog row/card. On the link-insertion tab the primary
     action is the insertion product (advertiser price only). Guest-post tab
     keeps the existing dual buttons. --}}
@php
    $buyAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
    $isLinkInsertionCatalog = $isLinkInsertionCatalog ?? false;
    $showLiPrimary = $isLinkInsertionCatalog && ! empty($offersLinkInsertion) && ($linkInsertionListPrice ?? 0) > 0;
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
        <div class="small text-muted mt-1">Link insertion {{ format_money($linkInsertionListPrice) }}</div>
        <button type="button" class="btn btn-sm btn-outline-primary buy-now d-inline-flex justify-content-center align-items-center gap-2 mt-1{{ $inLinkInsertionCart ? ' is-in-cart' : '' }}"
                data-id="{{ $site->id }}"
                data-base-price="{{ $linkInsertionListPrice }}"
                data-name="{{ $displayName }}"
                data-product-type="link_insertion"
                @if($inLinkInsertionCart) data-in-cart="1" @endif
                aria-label="{{ $inLinkInsertionCart ? 'Link insertion in cart' : 'Add link insertion for '.$identityLabel }}">
            @if($inLinkInsertionCart)
                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                <span>Insertion in cart</span>
            @else
                <i class="fa-solid fa-link" aria-hidden="true"></i>
                <span>Add link insertion</span>
            @endif
        </button>
    @endif
    <div class="catalog-buy-addon-hint small text-muted mt-1" data-site-id="{{ $site->id }}" hidden></div>
@endif
