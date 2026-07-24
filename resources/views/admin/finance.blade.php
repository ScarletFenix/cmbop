@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="mb-4">
        <h4 class="mb-1 fw-bold">Finance overview</h4>
        <p class="text-muted mb-0">Quick pulse on money in, money out, and order payments. Open a tile to work the queue.</p>
    </div>

    <div class="row g-3">
        @foreach($tiles as $key => $tile)
            <div class="col-md-6 col-xl-3">
                <a href="{{ $tile['url'] }}" class="text-decoration-none finance-tile">
                    <div class="card border-0 shadow-sm h-100 finance-tile__card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <div class="text-muted small fw-semibold text-uppercase">{{ $tile['label'] }}</div>
                                    <div class="fs-3 fw-bold text-{{ $tile['tone'] }} mt-1">{{ $tile['count'] }}</div>
                                </div>
                                <div class="bg-{{ $tile['tone'] }} bg-opacity-10 text-{{ $tile['tone'] }} rounded-3 d-flex align-items-center justify-content-center" style="width:44px;height:44px;">
                                    <i class="fa {{ $tile['icon'] }}"></i>
                                </div>
                            </div>
                            <div class="fs-5 fw-semibold text-dark mb-1">€{{ number_format($tile['amount'], 2) }}</div>
                            <div class="small text-muted">{{ $tile['hint'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <a href="{{ route('admin.deposits') }}" class="btn btn-outline-secondary w-100">
                <i class="fa fa-wallet me-1"></i> All deposits
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.withdrawals') }}" class="btn btn-outline-secondary w-100">
                <i class="fa fa-money-bill-wave me-1"></i> Payout queue
            </a>
        </div>
        <div class="col-md-4">
            <a href="{{ route('admin.invoices.index') }}" class="btn btn-outline-secondary w-100">
                <i class="fa fa-file-invoice-dollar me-1"></i> Invoices
            </a>
        </div>
    </div>
</div>

<style>
.finance-tile__card { transition: box-shadow .15s ease, transform .15s ease; }
.finance-tile:hover .finance-tile__card {
    box-shadow: 0 8px 24px rgba(15, 23, 42, .08) !important;
    transform: translateY(-1px);
}
</style>
@endsection
