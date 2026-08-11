@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Content Library</h1>
            <p class="text-muted mb-0">Browse advertiser articles across the marketplace.</p>
        </div>
        <a href="{{ route('admin.moderation.index') }}" class="btn btn-outline-secondary btn-sm">
            Moderation settings
        </a>
    </div>

    <form method="GET" action="{{ route('admin.content-library.index') }}" class="row g-2 align-items-end mb-3">
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Search</label>
            <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Title, file, email">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Status</label>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" @selected($status === 'all')>All ({{ (int) $statusCounts->sum() }})</option>
                <option value="approved" @selected($status === 'approved')>Approved ({{ (int) ($statusCounts['approved'] ?? 0) }})</option>
                <option value="rejected" @selected($status === 'rejected')>Rejected ({{ (int) ($statusCounts['rejected'] ?? 0) }})</option>
                <option value="pending" @selected($status === 'pending')>Pending ({{ (int) ($statusCounts['pending'] ?? 0) }})</option>
                <option value="processing" @selected($status === 'processing')>Processing ({{ (int) ($statusCounts['processing'] ?? 0) }})</option>
                <option value="error" @selected($status === 'error')>Error ({{ (int) ($statusCounts['error'] ?? 0) }})</option>
                <option value="archived" @selected($status === 'archived')>Archived ({{ (int) $archivedCount }})</option>
                <option value="expired" @selected($status === 'expired')>Expired ({{ (int) $expiredCount }})</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Language</label>
            <input type="text" name="language" class="form-control form-control-sm" value="{{ $language === 'all' ? '' : $language }}" placeholder="e.g. de">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Country</label>
            <input type="text" name="country" class="form-control form-control-sm" value="{{ $country === 'all' ? '' : $country }}" placeholder="e.g. de">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            <a href="{{ route('admin.content-library.index') }}" class="btn btn-sm btn-link">Reset</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Advertiser</th>
                        <th>Market</th>
                        <th>Status</th>
                        <th>Scores</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($submissions as $submission)
                    <tr>
                        <td class="text-muted small">#{{ $submission->id }}</td>
                        <td>
                            <div class="fw-semibold">{{ $submission->title ?: $submission->original_filename }}</div>
                            <div class="small text-muted">{{ $submission->original_filename }}</div>
                        </td>
                        <td>
                            <div>{{ $submission->user?->name ?: '—' }}</div>
                            <div class="small text-muted">{{ $submission->user?->email }}</div>
                        </td>
                        <td>
                            <span class="badge text-bg-light">
                                {{ strtoupper((string) $submission->country) }}/{{ strtoupper((string) $submission->language) }}
                            </span>
                        </td>
                        <td>
                            <span class="badge text-bg-secondary">{{ str_replace('_', ' ', (string) $submission->moderation_status) }}</span>
                            @if($submission->isArchived())
                                <span class="badge text-bg-dark">archived</span>
                            @elseif($submission->isExpired())
                                <span class="badge text-bg-warning text-dark">expired</span>
                            @endif
                        </td>
                        <td class="small text-muted">
                            @if($submission->evaluated_at)
                                U {{ $submission->uniqueness_score ?? '—' }}% · Q {{ $submission->quality_score ?? '—' }}%
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ optional($submission->expires_at)->format('Y-m-d') ?: '—' }}
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.content-library.show', $submission) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">No articles match these filters.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($submissions->hasPages())
            <div class="card-footer bg-white">{{ $submissions->links() }}</div>
        @endif
    </div>
</div>
@endsection
