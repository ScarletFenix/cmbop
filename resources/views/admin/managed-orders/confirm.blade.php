@extends('admin.layouts.app')

@section('title', 'Confirm managed order')

@section('content')
@php
    $advertiser = $preview['advertiser'];
    $site = $preview['site'];
    $total = $preview['total'];
    $balance = $preview['balance'];
    $sufficient = $preview['sufficient'];
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Confirm wallet charge',
        'subtitle' => 'Review the advertiser, site, and price before charging. Submitting twice with this token charges only once.',
        'actionUrl' => route('admin.managed-orders.create'),
        'actionLabel' => 'Back',
        'actionIcon' => 'fa-arrow-left',
    ])

    @unless($sufficient)
        <div class="alert alert-danger">
            Insufficient wallet balance. Needed €{{ number_format($total, 2) }}, available €{{ number_format($balance, 2) }}.
            Top up the advertiser wallet before creating this order.
        </div>
    @endunless

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted">Advertiser</div>
                    <div class="fw-semibold">{{ $advertiser->name }}</div>
                    <div class="small text-muted">{{ $advertiser->email }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Site</div>
                    <div class="fw-semibold">{{ $site->site_name }}</div>
                    <div class="small text-muted">{{ $site->domain ?: $site->site_url }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Charge (advertiser price)</div>
                    <div class="fw-semibold">€{{ number_format($total, 2) }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Current wallet balance</div>
                    <div class="fw-semibold">€{{ number_format($balance, 2) }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Fulfilment</div>
                    <div class="fw-semibold">Managed by SLB</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Anchor</div>
                    <div>{{ $preview['anchor_text'] }}</div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Target URL</div>
                    <div class="small"><a href="{{ $preview['target_url'] }}" target="_blank" rel="noopener">{{ $preview['target_url'] }}</a></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Content</div>
                    <div>
                        @if($preview['content_source'] === 'slb')
                            SLB writing
                        @else
                            Advertiser URL
                            @if($preview['content_url'])
                                · <a href="{{ $preview['content_url'] }}" target="_blank" rel="noopener">Open</a>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.managed-orders.store') }}" id="managedCreateChargeForm">
        @csrf
        <input type="hidden" name="advertiser_id" value="{{ $form['advertiser_id'] }}">
        <input type="hidden" name="site_id" value="{{ $form['site_id'] }}">
        <input type="hidden" name="anchor_text" value="{{ $form['anchor_text'] }}">
        <input type="hidden" name="target_url" value="{{ $form['target_url'] }}">
        <input type="hidden" name="content_source" value="{{ $form['content_source'] }}">
        <input type="hidden" name="content_url" value="{{ $form['content_url'] ?? '' }}">
        <input type="hidden" name="managed_assignee_id" value="{{ $form['managed_assignee_id'] ?? '' }}">
        <input type="hidden" name="managed_notes" value="{{ $form['managed_notes'] ?? '' }}">
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="confirm_charge" name="confirm_charge" @disabled(! $sufficient) required>
            <label class="form-check-label" for="confirm_charge">
                Charge €{{ number_format($total, 2) }} from {{ $advertiser->name }}’s wallet and create this Managed by SLB order.
            </label>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary" id="managedCreateSubmit" @disabled(! $sufficient)>
                Create order &amp; charge wallet
            </button>
            <a href="{{ route('admin.managed-orders.create') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('managedCreateChargeForm');
    const btn = document.getElementById('managedCreateSubmit');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = 'Charging…';
    });
})();
</script>
@endpush
