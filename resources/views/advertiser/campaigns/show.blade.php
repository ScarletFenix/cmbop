@extends('advertiser.layouts.app')

@section('content')
@php
    $counts = $counts ?? [];
    $isActive = (int) ($activeCampaignId ?? 0) === (int) $project->id;
@endphp

<div class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3 mb-4">
    <div>
        <a href="{{ route('advertiser.campaigns') }}" class="text-decoration-none small text-muted">
            <i class="fa fa-arrow-left me-1"></i> All campaigns
        </a>
        <h3 class="mb-1 mt-1">
            {{ $project->project_name }}
            @if($isActive)
                <span class="badge bg-primary-subtle text-primary align-middle">Active</span>
            @endif
        </h3>
        <a href="{{ $project->project_url }}" target="_blank" rel="noopener" class="text-muted text-decoration-none">
            {{ $project->project_url }}
            <i class="fa-solid fa-arrow-up-right-from-square ms-1 small"></i>
        </a>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('advertiser.campaigns.activate', $project) }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa fa-shopping-bag me-1"></i>
                {{ $isActive ? 'Continue shopping' : 'Shop for this campaign' }}
            </button>
        </form>
        <a href="{{ route('advertiser.orders') }}" class="btn btn-outline-secondary btn-sm">All orders</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-3 mb-4">
    @foreach([
        'not_started' => ['Not started', 'primary'],
        'in_progress' => ['In progress', 'info'],
        'waiting_approval' => ['Waiting approval', 'warning'],
        'needs_improvements' => ['Needs improvements', 'secondary'],
        'completed' => ['Completed', 'success'],
        'rejected' => ['Rejected', 'danger'],
    ] as $key => [$label, $tone])
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-1">{{ $label }}</div>
                    <div class="fs-4 fw-semibold text-{{ $tone }}">{{ $counts[$key] ?? 0 }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-box me-2"></i> Order packages in this campaign</span>
        <span class="text-muted small">{{ $counts['total'] ?? 0 }} placements</span>
    </div>
    <div class="card-body p-0">
        @if($orders->isEmpty())
            <div class="p-4 text-center text-muted">
                No order packages yet.
                <form method="POST" action="{{ route('advertiser.campaigns.activate', $project) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-link p-0 align-baseline">Shop the catalog</button>
                </form>
                to add placements.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Publisher site</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                            @php
                                $first = $order->items->first();
                                $bucket = $first
                                    ? app(\App\Services\Campaign\CampaignStatusService::class)->bucketFor($first)
                                    : 'not_started';
                                $bucketLabels = [
                                    'not_started' => 'Not started',
                                    'in_progress' => 'In progress',
                                    'waiting_approval' => 'Waiting approval',
                                    'needs_improvements' => 'Needs improvements',
                                    'completed' => 'Completed',
                                    'rejected' => 'Rejected',
                                ];
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">#{{ $order->order_number }}</div>
                                    <div class="small text-muted">{{ $order->created_at?->format('M j, Y') }}</div>
                                </td>
                                <td>
                                    @if($first)
                                        <div>{{ $first->site_name }}</div>
                                        <div class="small text-muted">{{ \Illuminate\Support\Str::limit($first->site_url, 40) }}</div>
                                    @else
                                        —
                                    @endif
                                    @if($order->items->count() > 1)
                                        <div class="small text-muted">+{{ $order->items->count() - 1 }} more</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $bucketLabels[$bucket] ?? ucfirst($bucket) }}</span>
                                    <div class="small text-muted">{{ ucfirst($order->status) }}</div>
                                </td>
                                <td>{{ ucfirst($order->payment_status) }}</td>
                                <td class="text-end">€{{ number_format((float) $order->total_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('advertiser.orders') }}?view={{ $order->id }}"
                                       class="btn btn-sm btn-outline-primary">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($orders->hasPages())
                <div class="p-3">{{ $orders->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
