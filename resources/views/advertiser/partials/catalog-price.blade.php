{{--
    Price for a listing: what you pay, what it was, and why.

    This used to live inside the Buy button as "Buy €113.00 €90.40", which made
    the CTA carry three competing bits of text. The number is the thing shoppers
    compare, so it gets its own line above the button.

    The .list-price-display / .base-price-display hooks stay: catalog.js rewrites
    them when a sensitive-topic add-on changes the total.

    @param float      $listPrice
    @param float|null $salePrice   null when there is no active offer
    @param float|null $salePercent
    @param string     $align       center (table) | start (card)
--}}
@php
    $priceAlign = ($align ?? 'center') === 'start' ? 'start' : 'center';
    $hasOffer = ($salePrice ?? null) !== null;
    $payPrice = $hasOffer ? $salePrice : $listPrice;
@endphp

<div class="catalog-price catalog-price--{{ $priceAlign }}">
    <div class="catalog-price__row">
        <span class="catalog-price__pay base-price-display">€{{ number_format((float) $payPrice, 2) }}</span>
        <span class="catalog-price__list list-price-display" {{ $hasOffer ? '' : 'hidden' }}>€{{ number_format((float) $listPrice, 2) }}</span>
    </div>
    @if($hasOffer && $salePercent)
        <span class="catalog-price__offer">
            <i class="fa-solid fa-tag" aria-hidden="true"></i>
            {{ rtrim(rtrim(number_format((float) $salePercent, 1), '0'), '.') }}% off
        </span>
    @endif
</div>
