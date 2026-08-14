@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Audience Inventory</h1>
            <p class="text-muted mb-0">Registered advertisers and publishers — download lists or use them for email campaigns.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-paper-plane me-1"></i> Updates / Campaigns
            </a>
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-sm btn-outline-secondary">
                Site Promotions
            </a>
        </div>
    </div>


    <div class="row g-3 mb-4">
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers']) }}</h3>
                    <div class="small text-muted mt-1">Users with advertiser role</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publishers</div>
                    <h3 class="mb-0">{{ number_format($stats['publishers']) }}</h3>
                    <div class="small text-muted mt-1">Users with publisher role</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Unique (either role)</div>
                    <h3 class="mb-0">{{ number_format($stats['both_unique']) }}</h3>
                    <div class="small text-muted mt-1">Combined reach without duplicates</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">No orders</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers_no_orders'] ?? 0) }}</h3>
                    <div class="small text-muted mt-1">Advertisers who never ordered</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">No sites</div>
                    <h3 class="mb-0">{{ number_format($stats['publishers_no_sites'] ?? 0) }}</h3>
                    <div class="small text-muted mt-1">Publishers who never listed a site</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Never deposited</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers_never_deposited'] ?? 0) }}</h3>
                    <div class="small text-muted mt-1">Advertisers who never funded a wallet</div>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3 flex-wrap">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'advertisers' ? 'active' : '' }}"
               href="{{ route('admin.audiences.index', ['tab' => 'advertisers', 'q' => $search]) }}">
                <i class="fa fa-bullseye me-1"></i> Advertisers
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['advertisers'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'publishers' ? 'active' : '' }}"
               href="{{ route('admin.audiences.index', ['tab' => 'publishers', 'q' => $search]) }}">
                <i class="fa fa-globe me-1"></i> Publishers
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['publishers'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'no_orders' ? 'active' : '' }}"
               href="{{ route('admin.audiences.index', ['tab' => 'no_orders', 'q' => $search]) }}">
                <i class="fa fa-shopping-bag me-1"></i> No orders
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['advertisers_no_orders'] ?? 0 }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'no_sites' ? 'active' : '' }}"
               href="{{ route('admin.audiences.index', ['tab' => 'no_sites', 'q' => $search]) }}">
                <i class="fa fa-link me-1"></i> No sites
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['publishers_no_sites'] ?? 0 }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'never_deposited' ? 'active' : '' }}"
               href="{{ route('admin.audiences.index', ['tab' => 'never_deposited', 'q' => $search]) }}">
                <i class="fa fa-wallet me-1"></i> Never deposited
                <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['advertisers_never_deposited'] ?? 0 }}</span>
            </a>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <form method="GET" class="d-flex gap-2" action="{{ route('admin.audiences.index') }}">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div style="min-width:220px;">
                    <x-slb-search-field name="q" id="adminAudiencesSearch" :value="$search" placeholder="Search name or email" />
                </div>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
            </form>
            <div class="d-flex gap-2">
                @php
                    $exportLabel = match ($tab) {
                        'publishers' => 'Publishers',
                        'no_orders' => 'No orders',
                        'no_sites' => 'No sites',
                        'never_deposited' => 'Never deposited',
                        default => 'Advertisers',
                    };
                @endphp
                <a href="{{ route('admin.audiences.export', ['audience' => $tab]) }}" class="btn btn-sm btn-outline-success">
                    <i class="fa fa-download me-1"></i> Download {{ $exportLabel }} CSV
                </a>
                <a href="{{ route('admin.campaigns.index', ['audience' => $campaignAudience]) }}" class="btn btn-sm btn-primary">
                    <i class="fa fa-envelope me-1"></i> Email this audience
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Roles</th>
                            <th>Active role</th>
                            <th>Verified</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td>{{ $user->id }}</td>
                                <td class="fw-semibold">{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @foreach($user->roles as $role)
                                        <span class="badge bg-light text-dark">{{ $role->name }}</span>
                                    @endforeach
                                </td>
                                <td class="small text-muted">{{ $user->activeRole() ?: '—' }}</td>
                                <td>
                                    @if($user->hasVerifiedEmail())
                                        <span class="badge bg-success">Yes</span>
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ optional($user->created_at)->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">No users in this audience yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($users->hasPages())
            <div class="card-footer bg-white">{{ $users->links() }}</div>
        @endif
    </div>
</div>
@endsection
