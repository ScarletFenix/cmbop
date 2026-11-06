@extends('admin.layouts.app')

@section('title', 'Confirm managed order')

@section('content')
@php
    $advertiser = $preview['advertiser'];
    $total = $preview['total'];
    $balance = $preview['balance'];
    $sufficient = $preview['sufficient'];
    $quote = $preview['quote'] ?? [];
    $placements = $preview['placements'] ?? [];
    $orderCount = (int) ($quote['order_count'] ?? count($placements));
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Confirm wallet charge',
        'subtitle' => 'Review advertiser, sites, and price before charging. Submitting twice with this token charges only once.',
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
                <div class="col-md-3">
                    <div class="small text-muted">Charge (wallet once)</div>
                    <div class="fw-semibold">€{{ number_format($total, 2) }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Current wallet balance</div>
                    <div class="fw-semibold">€{{ number_format($balance, 2) }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Fulfilment</div>
                    <div class="fw-semibold">Managed by SLB</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Placements</div>
                    <div class="fw-semibold">{{ $orderCount }}</div>
                </div>
                @if(!empty($preview['external_reference']))
                    <div class="col-md-4">
                        <div class="small text-muted">External reference</div>
                        <div class="fw-semibold text-break">{{ $preview['external_reference'] }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-0"><strong>Line items</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Site</th>
                            <th>Qty</th>
                            <th>Content</th>
                            <th>Links</th>
                            <th class="text-end">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($quote['lines'] ?? []) as $i => $line)
                            @php $placement = $placements[$i] ?? null; @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $line['site_name'] }}</div>
                                    @if(!empty($line['pack']))
                                        <div class="small text-muted">Pack pricing</div>
                                    @endif
                                </td>
                                <td>{{ $line['quantity'] }}</td>
                                <td class="small">
                                    @if(($line['content_source'] ?? '') === 'slb')
                                        SLB writing
                                        @if(($line['writing_fee'] ?? 0) > 0)
                                            (+€{{ number_format($line['writing_fee'], 2) }})
                                        @endif
                                    @elseif(!empty($placement['is_library']))
                                        Content Library
                                    @else
                                        Advertiser URL
                                    @endif
                                </td>
                                <td class="small">
                                    @foreach(($line['links'] ?? []) as $link)
                                        <div>{{ $link['anchor'] }} → <span class="text-muted">{{ \Illuminate\Support\Str::limit($link['url'], 40) }}</span></div>
                                    @endforeach
                                </td>
                                <td class="text-end">€{{ number_format($line['line_total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.managed-orders.store') }}" id="managedCreateChargeForm">
        @csrf
        <input type="hidden" name="advertiser_id" value="{{ $form['advertiser_id'] }}">
        <input type="hidden" name="managed_assignee_id" value="{{ $form['managed_assignee_id'] ?? '' }}">
        <input type="hidden" name="managed_notes" value="{{ $form['managed_notes'] ?? '' }}">
        <input type="hidden" name="managed_external_reference" value="{{ $form['managed_external_reference'] ?? '' }}">
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

        @foreach(($form['sites'] ?? []) as $si => $site)
            <input type="hidden" name="sites[{{ $si }}][site_id]" value="{{ $site['site_id'] }}">
            <input type="hidden" name="sites[{{ $si }}][quantity]" value="{{ $site['quantity'] ?? 1 }}">
            <input type="hidden" name="sites[{{ $si }}][content_source]" value="{{ $site['content_source'] }}">
            <input type="hidden" name="sites[{{ $si }}][content_url]" value="{{ $site['content_url'] ?? '' }}">
            <input type="hidden" name="sites[{{ $si }}][content_submission_id]" value="{{ $site['content_submission_id'] ?? '' }}">
            <input type="hidden" name="sites[{{ $si }}][writing_brief_notes]" value="{{ $site['writing_brief_notes'] ?? '' }}">
            @foreach(($site['links'] ?? []) as $li => $link)
                <input type="hidden" name="sites[{{ $si }}][links][{{ $li }}][anchor]" value="{{ $link['anchor'] ?? ($link['anchor_text'] ?? '') }}">
                <input type="hidden" name="sites[{{ $si }}][links][{{ $li }}][url]" value="{{ $link['url'] ?? ($link['target_url'] ?? '') }}">
            @endforeach
        @endforeach

        {{-- Legacy single-site keys when confirm was posted that way --}}
        @if(empty($form['sites']) && !empty($form['site_id']))
            <input type="hidden" name="site_id" value="{{ $form['site_id'] }}">
            <input type="hidden" name="anchor_text" value="{{ $form['anchor_text'] }}">
            <input type="hidden" name="target_url" value="{{ $form['target_url'] }}">
            <input type="hidden" name="content_source" value="{{ $form['content_source'] }}">
            <input type="hidden" name="content_url" value="{{ $form['content_url'] ?? '' }}">
        @endif

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" value="1" id="confirm_charge" name="confirm_charge" @disabled(! $sufficient) required>
            <label class="form-check-label" for="confirm_charge">
                Charge €{{ number_format($total, 2) }} from {{ $advertiser->name }}’s wallet once and create
                {{ $orderCount }} Managed by SLB placement{{ $orderCount === 1 ? '' : 's' }}.
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
