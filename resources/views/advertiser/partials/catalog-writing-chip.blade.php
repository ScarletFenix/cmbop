@php
    $writingOffered = false;
    $writingFeeLabel = '';
    $guaranteeOffered = false;
    try {
        $fees = app(\App\Services\Marketplace\WritingFeeService::class);
        $addons = app(\App\Services\Marketplace\CheckoutAddOnService::class);
        $writingOffered = $fees->writingOffered($site);
        $writer = $addons->requestedWriter($site);
        $fee = $addons->checkoutViewItems([['id' => $site->id]])[0]['writing_fee'] ?? 0;
        $writingFeeLabel = $fee > 0 ? format_money_pay($fee) : '';
        $guaranteeOffered = $addons->siteQualifiesForGuarantee($site);
    } catch (\Throwable $e) {
        report($e);
    }
@endphp
<p class="small text-muted mb-0 mt-2">
    @if($writingOffered)
        Publisher can write the article
        @if($writingFeeLabel !== '')
            ({{ $writingFeeLabel }} writing fee)
        @endif
        — choose in the cart.
    @else
        SEOLinkBuildings can write the article
        @if($writingFeeLabel !== '')
            (flat {{ $writingFeeLabel }} writing fee, paid to SEOLinkBuildings)
        @endif
        — choose in the cart.
    @endif
</p>
@if($guaranteeOffered)
    <p class="small text-muted mb-0">Optional 12-month link guarantee at checkout (publication period is 12 months or permanent).</p>
@endif
@if($site->offersLinkInsertions())
    <p class="small text-muted mb-0">Link insertion available — {{ \App\Support\LinkInsertionProduct::PERMANENT_COPY }} Optional {{ \App\Support\LinkInsertionProduct::GUARANTEE_LABEL }} (10% of the item) at checkout.</p>
@endif
