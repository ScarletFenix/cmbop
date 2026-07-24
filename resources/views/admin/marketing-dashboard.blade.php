@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Marketing Dashboard</h1>
            <p class="text-muted mb-0">Add and edit publisher sites, manage bulk onboarding, and refresh enrichment. Admin approves live listings.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-globe me-1"></i> Manage Sites
            </a>
            <a href="{{ staff_route('bulk-site-requests.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-layer-group me-1"></i> Bulk requests
            </a>
            <a href="{{ staff_route('site-enrichment.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-line me-1"></i> Enrichment
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Pending (not verified)</div>
                    <h3 class="mb-0 text-warning">{{ $stats['unverified_sites'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Verified Sites</div>
                    <h3 class="mb-0 text-success">{{ $stats['verified_sites'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Active</div>
                    <h3 class="mb-0 text-primary">{{ $stats['active_sites'] }}</h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Inactive</div>
                    <h3 class="mb-0 text-secondary">{{ $stats['inactive_sites'] }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
            <strong><i class="fa fa-clock me-2 text-warning"></i>Pending sites (awaiting admin approval)</strong>
            <a href="{{ staff_route('sites.index') }}" class="small">View all sites</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Site</th>
                            <th>Publisher</th>
                            <th>Submitted</th>
                            <th width="140">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingSites as $site)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $site->site_name ?: '—' }}</div>
                                    <div class="small text-muted text-truncate" style="max-width:240px;">{{ $site->site_url }}</div>
                                </td>
                                <td>
                                    <div>{{ $site->publisher?->name ?? 'Unknown' }}</div>
                                    <div class="small text-muted">{{ $site->publisher?->email }}</div>
                                </td>
                                <td class="small text-muted">{{ optional($site->created_at)->format('d M Y') }}</td>
                                <td>
                                    <a href="{{ staff_route('sites.edit', $site->id) }}" class="btn btn-sm btn-outline-primary">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No pending sites right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-info border-0 mt-4 mb-0">
        <i class="fa fa-info-circle me-1"></i>
        Marketing can <strong>add/edit sites</strong>, manage <strong>bulk onboarding</strong>, <strong>refresh enrichment</strong>, and <strong>delete pending (not-live) sites</strong>.
        Verify, activate, users, payments, and orders are admin-only.
    </div>

</div>
@endsection
