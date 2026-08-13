@extends('publisher.layouts.app')

@section('title', 'Balance')

@section('content')
@php
    $publisher = $publisher ?? \App\Models\Wallet::emptyRoleSnapshot();
    $advertiser = $advertiser ?? \App\Models\Wallet::emptyRoleSnapshot();
    $minWithdrawalAmount = (float) ($minWithdrawalAmount ?? config('billing.withdrawal_min_amount', 20));
    $canWithdraw = (bool) ($canWithdraw ?? false);
    $showAdvertiserWallet = (bool) ($showAdvertiserWallet ?? false);
    $publisherDebt = (float) ($publisher['debt'] ?? $publisherDebt ?? 0);
    $withdrawDisabledReason = $publisherDebt > 0
        ? 'Withdrawals are blocked while you have outstanding clawback debt of €'.number_format($publisherDebt, 2).'. Contact support to resolve this before withdrawing.'
        : 'You need at least €'.number_format($minWithdrawalAmount, 2).' withdrawable balance to request a payout. Available now: €'.number_format((float) $publisher['withdrawable'], 2).'.';
@endphp
<link rel="stylesheet" href="{{ asset('assets/css/publisher-balance.css') }}?v={{ @filemtime(public_path('assets/css/publisher-balance.css')) ?: '1' }}">

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-1 fw-semibold">Balance</h1>
            <p class="text-muted mb-0">
                Publisher earnings on this wallet. Internal transfers to an Advertiser wallet are no longer offered.
            </p>
        </div>
    </div>

    @if($publisherDebt > 0)
        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
            <strong>Outstanding clawback debt:</strong> €{{ number_format($publisherDebt, 2) }}.
            Withdrawals are blocked until support clears this debt.
        </div>
    @endif

    <div class="pb-wallet-grid mb-4">
        <article class="pb-wallet-card" aria-labelledby="publisherEarningsLabel">
            <div class="pb-wallet-card__header">
                <span class="pb-wallet-card__label" id="publisherEarningsLabel">Publisher earnings</span>
                <x-glass-tip
                    title="Publisher earnings"
                    body="Cash you can withdraw. Bonus is for purchases only and is not included. Amounts on hold have already left this total. Clawback debt blocks withdrawals; it does not reduce this number."
                    label="About publisher earnings"
                    placement="top" />
            </div>
            <div class="pb-wallet-card__value" id="publisherBalance">€{{ number_format((float) $publisher['withdrawable'], 2) }}</div>
            <p class="pb-wallet-card__sub">Withdrawable</p>

            @if((float) $publisher['reserved'] > 0 || (float) $publisher['bonus'] > 0 || (float) $publisher['debt'] > 0)
                <div class="pb-wallet-card__chips">
                    @if((float) $publisher['reserved'] > 0)
                        <div class="pb-wallet-card__chip">
                            <span class="pb-wallet-card__chip-label">On hold</span>
                            <span class="pb-wallet-card__chip-value">€{{ number_format((float) $publisher['reserved'], 2) }}</span>
                        </div>
                    @endif
                    @if((float) $publisher['bonus'] > 0)
                        <div class="pb-wallet-card__chip pb-wallet-card__chip--bonus">
                            <span class="pb-wallet-card__chip-label">Bonus</span>
                            <span class="pb-wallet-card__chip-value">€{{ number_format((float) $publisher['bonus'], 2) }}</span>
                        </div>
                    @endif
                    @if((float) $publisher['debt'] > 0)
                        <div class="pb-wallet-card__chip pb-wallet-card__chip--debt">
                            <span class="pb-wallet-card__chip-label">Debt</span>
                            <span class="pb-wallet-card__chip-value">€{{ number_format((float) $publisher['debt'], 2) }}</span>
                        </div>
                    @endif
                </div>
            @endif

            @if($canWithdraw)
                <p class="pb-wallet-card__status pb-wallet-card__status--ready">Ready to withdraw</p>
            @else
                <p class="pb-wallet-card__status" id="withdrawBlockedReason">{{ $withdrawDisabledReason }}</p>
            @endif

            <div class="pb-wallet-card__actions">
                @if($canWithdraw)
                    <a href="{{ route('publisher.withdraw') }}" class="btn btn-primary" id="withdrawCta">
                        Withdraw
                    </a>
                @else
                    <span class="pb-disabled-wrap" tabindex="0" data-glass-tip data-glass-tip-body="{{ $withdrawDisabledReason }}" data-glass-tip-placement="top">
                        <button type="button" class="btn btn-primary" id="withdrawCta" disabled>
                            Withdraw
                        </button>
                    </span>
                @endif
            </div>
        </article>

        @if($showAdvertiserWallet)
            <article class="pb-wallet-card" aria-labelledby="advertiserSpendableLabel">
                <div class="pb-wallet-card__header">
                    <span class="pb-wallet-card__label" id="advertiserSpendableLabel">Advertiser (spendable)</span>
                    <x-glass-tip
                        title="Advertiser spendable"
                        body="Money you can spend on placements. Bonus is welcome credit for purchases only and cannot be withdrawn."
                        label="About advertiser spendable"
                        placement="top" />
                </div>
                <div class="pb-wallet-card__value" id="advertiserBalance">€{{ number_format((float) $advertiser['spendable'], 2) }}</div>
                <p class="pb-wallet-card__sub">Spendable</p>

                <div class="pb-wallet-card__chips">
                    <div class="pb-wallet-card__chip">
                        <span class="pb-wallet-card__chip-label">Money</span>
                        <span class="pb-wallet-card__chip-value">€{{ number_format((float) $advertiser['withdrawable'], 2) }}</span>
                    </div>
                    <div class="pb-wallet-card__chip pb-wallet-card__chip--bonus">
                        <span class="pb-wallet-card__chip-label">Bonus</span>
                        <span class="pb-wallet-card__chip-value">€{{ number_format((float) $advertiser['bonus'], 2) }}</span>
                    </div>
                </div>

                @if((float) $advertiser['bonus'] > 0)
                    <p class="pb-wallet-card__note">
                        <strong>Bonus €{{ number_format((float) $advertiser['bonus'], 2) }}</strong>
                        (purchases only) — {{ \App\Models\Wallet::PROMOTIONAL_BONUS_MESSAGE }}
                    </p>
                @endif

                <p class="pb-wallet-card__note">Publisher earnings cannot be moved into this wallet here.</p>

                <div class="pb-wallet-card__actions">
                    <a href="{{ route('advertiser.add-funds') }}" class="btn btn-primary" id="addFundsCta">Add Funds</a>
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-outline-secondary" id="catalogCta">Catalog</a>
                </div>
            </article>
        @endif
    </div>

    <div class="alert alert-light border mb-0" role="status">
        Internal wallet transfers are no longer offered. Use Withdraw for payouts, or switch to Advertiser to spend or add funds.
    </div>
</div>
@endsection
