{{-- Buy CTAs for one catalog row/card. Primary price is a single large number
     (same style as a GP-only row). When the site also offers the other product,
     a small secondary line names that product and its advertiser list price. --}}
@php
    $buyAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
    $isLinkInsertionCatalog = $isLinkInsertionCatalog ?? false;
    $showLiPrimary = $isLinkInsertionCatalog && ! empty($offersLinkInsertion) && ($linkInsertionListPrice ?? 0) > 0;
    $guestPostListPrice = $guestPostListPrice ?? $catalogListPrice ?? 0;
    $showDualPrices = ! empty($offersLinkInsertion)
        && ($linkInsertionListPrice ?? 0) > 0
        && (float) $guestPostListPrice > 0
        && empty($isOwnedByMe);
    $includedBits = [];
    if ($showLiPrimary) {
        $liDays = $site->linkInsertionDeliveryLabel();
        if ($liDays) {
            $includedBits[] = $liDays;
        }
    } else {
        $gpDays = $site->turnaroundLabel();
        if ($gpDays) {
            $includedBits[] = $gpDays;
        }
        $duration = $site->publicationDurationLabel();
        if ($duration) {
            $includedBits[] = $duration;
        }
    }
@endphp
@if($isOwnedByMe)
    @include('advertiser.partials.catalog-own-listing', ['align' => $buyAlign])
@else
    @if($showLiPrimary)
        <div class="catalog-price catalog-price--{{ $buyAlign }}">
            <div class="catalog-price__row">
                <span class="catalog-price__pay base-price-display">{{ format_money($linkInsertionListPrice) }}</span>
            </div>
        </div>
    @else
        @include('advertiser.partials.catalog-price', [
            'listPrice' => $catalogListPrice,
            'salePrice' => $catalogSalePrice,
            'salePercent' => $catalogSalePctDisplay,
            'align' => $buyAlign,
            'bulkLabel' => $catalogDeals['bulk_note'] ?? null,
            'showFrom' => ! empty($catalogDeals['has_paid_addons']),
            'siteId' => $site->id,
            'featured' => $site->isFeatured(),
        ])
    @endif
    @if($showDualPrices)
        <p class="catalog-price-alt">
            @if($showLiPrimary)
                {{ __('messages.catalog_price_guest_post', ['price' => format_money($guestPostListPrice)]) }}
            @else
                {{ __('messages.catalog_price_link_insertion', ['price' => format_money($linkInsertionListPrice)]) }}
            @endif
        </p>
    @endif
    @if($includedBits !== [])
        <p class="catalog-included">{{ implode(' · ', $includedBits) }}</p>
    @endif
    @if($showLiPrimary)
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
    @endif
    <div class="catalog-buy-addon-hint small text-muted mt-1" data-site-id="{{ $site->id }}" hidden></div>
@endif
