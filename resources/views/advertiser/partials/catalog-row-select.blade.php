{{-- Row/card checkbox for bulk add-to-cart and compare. --}}
@php
    $selectName = $displayName ?? 'site';
    $selectDisabled = ! empty($isOwnedByMe);
@endphp
@unless($selectDisabled)
<label class="catalog-select">
    <input type="checkbox"
           class="catalog-select__input"
           data-catalog-select
           value="{{ $site->id }}"
           aria-label="{{ __('messages.catalog_select_site', ['name' => $selectName]) }}">
    <span class="catalog-select__box" aria-hidden="true"></span>
</label>
@endunless
