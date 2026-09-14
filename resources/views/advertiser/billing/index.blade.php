@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4 align-items-end g-3">
        <div class="col-md-8">
            <h2 class="mb-1 fw-semibold">Billing &amp; Invoices</h2>
            <p class="text-muted mb-0">Download tax invoices, payment receipts, refunds, and wallet deposit receipts.</p>
            <p class="small text-muted mb-0 mt-1">
                Documents use your company name, address, and optional VAT / tax ID from checkout billing or
                <a href="{{ route('profile') }}">profile</a>.
                Unpaid bank, Wise, or crypto pay-in slips stay on
                <a href="{{ route('advertiser.add-funds') }}">Add Funds</a>
                until we credit your wallet — those are not tax invoices.
            </p>
        </div>
        <div class="col-md-4 text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
            <a href="{{ route('advertiser.add-funds') }}" class="btn btn-sm btn-outline-secondary">Add funds</a>
            <a href="{{ route('advertiser.orders') }}" class="btn btn-sm btn-outline-secondary">View orders</a>
            <a href="{{ route('advertiser.billing.export', request()->query()) }}" class="btn btn-sm btn-cta-tertiary">Export CSV</a>
        </div>
    </div>

    @if(($pendingPayIns ?? 0) > 0)
        <div class="alert alert-warning border mb-3" role="status">
            You have a pending pay-in on
            <a href="{{ route('advertiser.add-funds') }}" class="alert-link">Add Funds</a>.
            It will appear here as a deposit receipt after we credit your wallet.
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('advertiser.billing.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <x-slb-search-field name="search" id="advertiserBillingSearch" :value="request('search')" placeholder="Invoice #, order #, REF, transaction…" />
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['paid','issued','pending','failed','refunded','cancelled'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="tax_invoice" @selected(request('type')==='tax_invoice')>Invoice</option>
                        <option value="payment_receipt" @selected(request('type')==='payment_receipt')>Receipt</option>
                        <option value="refund_receipt" @selected(request('type')==='refund_receipt')>Refund</option>
                        <option value="payment_failure" @selected(request('type')==='payment_failure')>Failed attempt</option>
                        <option value="deposit_receipt" @selected(request('type')==='deposit_receipt')>Deposit receipt</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">From</label>
                    <input type="date" name="from" value="{{ $filterFrom ?? '' }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">To</label>
                    <input type="date" name="to" value="{{ $filterTo ?? '' }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('advertiser.billing.index') }}" class="btn btn-sm btn-cta-tertiary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Reference</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Type</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoices as $invoice)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $invoice->invoice_number }}</div>
                                    @if($invoice->transaction_id)
                                        <div class="small text-muted text-truncate" style="max-width:180px;">{{ $invoice->transaction_id }}</div>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($invoice->order_id)
                                        <a href="{{ route('advertiser.orders') }}">{{ $invoice->referenceLabel() }}</a>
                                    @else
                                        {{ $invoice->referenceLabel() }}
                                    @endif
                                </td>
                                <td class="small">{{ optional($invoice->invoice_date)->format('M j, Y') }}</td>
                                <td class="fw-semibold">€{{ number_format((float) $invoice->total_amount, 2) }}</td>
                                <td>
                                    <span class="badge text-bg-{{ $invoice->statusBadgeClass() }}">{{ ucfirst($invoice->status) }}</span>
                                </td>
                                <td class="small">{{ $invoice->typeLabel() }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                        <a href="{{ route('advertiser.billing.show', $invoice) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                        @if($invoice->advertiserCanDownloadPdf())
                                            <a href="{{ route('advertiser.billing.view', $invoice) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">View PDF</a>
                                            <a href="{{ route('advertiser.billing.download', $invoice) }}" class="btn btn-sm btn-primary">Download PDF</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-5">
                                    <x-ui.empty-state
                                        icon="fa-file-invoice"
                                        title="No invoices yet"
                                        message="Tax invoices and receipts appear after a paid order. Wallet deposit receipts appear after we credit a top-up. Refunds and failed-payment documents show up here too."
                                        primary-label="Browse catalog"
                                        :primary-url="route('advertiser.catalog')"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($invoices->hasPages())
            <div class="card-footer bg-white border-0">{{ $invoices->links() }}</div>
        @endif
    </div>
</div>
@endsection
