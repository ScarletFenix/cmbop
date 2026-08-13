@extends('publisher.layouts.app')

@section('title', 'Balance')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-1 fw-semibold">Balance</h1>
            <p class="text-muted mb-0">
                Publisher earnings on this wallet. Internal transfers to an Advertiser wallet are no longer offered.
            </p>
        </div>
    </div>

    @if(($publisherDebt ?? 0) > 0)
        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
            <strong>Outstanding clawback debt:</strong> €{{ number_format((float) $publisherDebt, 2) }}.
            Withdrawals are blocked until support clears this debt.
        </div>
    @endif

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fa fa-wallet me-2 text-primary"></i>
                    Publisher earnings
                    <x-glass-tip
                        class="ms-1"
                        title="Publisher earnings"
                        body="Cash you can withdraw, after bonus (purchases only), amounts on hold, and clawback debt."
                        label="About publisher earnings"
                        placement="top" />
                </div>
                <div class="card-body text-center">
                    <h2 class="mb-0" id="publisherBalance" style="color: #10b981;">€{{ number_format((float) ($publisher['withdrawable'] ?? $publisherBalance), 2) }}</h2>
                    <p class="text-muted small mt-2 mb-0">Withdrawable</p>
                    @if((float) ($publisher['spendable'] ?? 0) !== (float) ($publisher['withdrawable'] ?? 0))
                        <p class="text-muted small mb-0">Wallet total €{{ number_format((float) $publisher['spendable'], 2) }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-light border mb-0" role="status">
        Internal wallet transfers are no longer offered. Use Withdraw for payouts, or switch to Advertiser to spend or add funds.
    </div>
</div>
@endsection
