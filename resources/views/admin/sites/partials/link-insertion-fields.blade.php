@php
    $site = $site ?? null;
    $editable = $editable ?? true;
    $layout = $layout ?? 'card';
    $namePrefix = $namePrefix ?? '';
    $idPrefix = $idPrefix ?? 'li';
    $disabled = ! $editable;
    $dotPrefix = $namePrefix === ''
        ? ''
        : preg_replace('/\[(\d+)\]/', '.$1', $namePrefix).'.';
    $nameOf = static fn (string $field): string => $namePrefix === '' ? $field : $namePrefix.'['.$field.']';
    $idOf = static fn (string $field): string => $idPrefix.'_'.$field;
    $existingPrice = $site?->listingLinkInsertionAmount() ?: ($site?->publisherLinkInsertionPrice() ?: null);
    $accepts = old($dotPrefix.'accepts_link_insertions', $site?->offersLinkInsertions() ? '1' : '');
    $priceValue = old_text($dotPrefix.'link_insertion_price', $existingPrice !== null && $existingPrice > 0 ? $existingPrice : '');
    $deliveryValue = old($dotPrefix.'link_insertion_delivery_days', $site?->link_insertion_delivery_days ?? 7);
    $minAgeValue = old_text($dotPrefix.'link_insertion_min_article_age_days', $site?->link_insertion_min_article_age_days);
    $linkTypeValue = old($dotPrefix.'link_insertion_link_type', $site?->link_insertion_link_type ?: 'dofollow');
    $allowHighTraffic = old($dotPrefix.'link_insertion_allow_high_traffic', $site?->link_insertion_allow_high_traffic ? '1' : '');
@endphp
@if($layout === 'card')
<div class="card border-0 shadow-sm mb-3 staff-assign-site-section">
    <div class="card-body">
        <h5 class="fw-semibold mb-1">Link insertions</h5>
        <p class="small text-muted mb-3">{{ \App\Support\LinkInsertionProduct::PERMANENT_COPY }} Publisher price in EUR. Advertisers see this plus the same platform fee as guest posts — do not enter the advertiser price.</p>
        <div class="row g-3">
@else
<div class="col-12">
    <div class="border rounded p-3 bg-light">
        <div class="fw-semibold mb-1">Link insertions</div>
        <p class="small text-muted mb-3">{{ \App\Support\LinkInsertionProduct::PERMANENT_COPY }} Publisher price in EUR. Advertisers see this plus the same platform fee as guest posts.</p>
        <div class="row g-3">
@endif
            <input type="hidden" name="{{ $nameOf('link_insertion_form') }}" value="1">
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input @error($dotPrefix.'accepts_link_insertions') is-invalid @enderror"
                           type="checkbox"
                           name="{{ $nameOf('accepts_link_insertions') }}"
                           id="{{ $idOf('accepts_link_insertions') }}"
                           value="1"
                           @checked($accepts)
                           @disabled($disabled)>
                    <label class="form-check-label" for="{{ $idOf('accepts_link_insertions') }}">Accept link insertions</label>
                </div>
                @error($dotPrefix.'accepts_link_insertions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="{{ $idOf('link_insertion_price') }}">Link insertion price (€)</label>
                <input type="number"
                       id="{{ $idOf('link_insertion_price') }}"
                       name="{{ $nameOf('link_insertion_price') }}"
                       class="form-control @error($dotPrefix.'link_insertion_price') is-invalid @enderror"
                       min="0" max="999999.99" step="0.01"
                       value="{{ $priceValue }}"
                       placeholder="Publisher price"
                       @disabled($disabled)>
                <div class="form-text">Same markup path as guest-post price. Leave blank if this site does not offer insertions.</div>
                @error($dotPrefix.'link_insertion_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="{{ $idOf('link_insertion_delivery_days') }}">Delivery (days)</label>
                <input type="number"
                       id="{{ $idOf('link_insertion_delivery_days') }}"
                       name="{{ $nameOf('link_insertion_delivery_days') }}"
                       class="form-control @error($dotPrefix.'link_insertion_delivery_days') is-invalid @enderror"
                       min="1" max="60"
                       value="{{ $deliveryValue }}"
                       @disabled($disabled)>
                @error($dotPrefix.'link_insertion_delivery_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="{{ $idOf('link_insertion_min_article_age_days') }}">Min. article age (days)</label>
                <input type="number"
                       id="{{ $idOf('link_insertion_min_article_age_days') }}"
                       name="{{ $nameOf('link_insertion_min_article_age_days') }}"
                       class="form-control @error($dotPrefix.'link_insertion_min_article_age_days') is-invalid @enderror"
                       min="0" max="3650"
                       value="{{ $minAgeValue }}"
                       placeholder="Optional"
                       @disabled($disabled)>
                @error($dotPrefix.'link_insertion_min_article_age_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold d-block">Link type</label>
                <div class="d-flex flex-wrap gap-3 mt-1">
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="{{ $nameOf('link_insertion_link_type') }}"
                               id="{{ $idOf('link_type_dofollow') }}"
                               value="dofollow"
                               @checked($linkTypeValue !== 'nofollow')
                               @disabled($disabled)>
                        <label class="form-check-label" for="{{ $idOf('link_type_dofollow') }}">Dofollow</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio"
                               name="{{ $nameOf('link_insertion_link_type') }}"
                               id="{{ $idOf('link_type_nofollow') }}"
                               value="nofollow"
                               @checked($linkTypeValue === 'nofollow')
                               @disabled($disabled)>
                        <label class="form-check-label" for="{{ $idOf('link_type_nofollow') }}">Nofollow</label>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="form-check mt-md-4">
                    <input class="form-check-input" type="checkbox"
                           name="{{ $nameOf('link_insertion_allow_high_traffic') }}"
                           id="{{ $idOf('link_insertion_allow_high_traffic') }}"
                           value="1"
                           @checked($allowHighTraffic)
                           @disabled($disabled)>
                    <label class="form-check-label" for="{{ $idOf('link_insertion_allow_high_traffic') }}">Allow homepage / high-traffic posts</label>
                </div>
            </div>
        </div>
@if($layout === 'card')
    </div>
</div>
@else
    </div>
</div>
@endif
