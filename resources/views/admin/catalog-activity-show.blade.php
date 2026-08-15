@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => $account->name ?: $account->email,
        'subtitle' => 'Copy events and hide-mode unlocks for this account. Open-catalog browsing is not logged.',
    ])

    <div class="mb-3 d-flex flex-wrap gap-2">
        <a href="{{ route('admin.catalog-activity', ['user' => $account->id]) }}" class="btn btn-sm btn-outline-secondary">Back to queue</a>
        <a href="{{ $userUrl }}" class="btn btn-sm btn-outline-secondary">Open user</a>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="small text-muted">{{ $account->email }}</div>
            <div class="mt-2">
                @if($status === \App\Models\User::CATALOG_COPY_HIDDEN)
                    <span class="badge bg-danger">Hide mode</span>
                    @if($account->catalog_hide_until)
                        <span class="small text-muted ms-1">until {{ $account->catalog_hide_until->timezone(config('app.timezone'))->format('M j, H:i') }}</span>
                    @endif
                @elseif($status === \App\Models\User::CATALOG_COPY_POST_HIDE)
                    <span class="badge bg-secondary">Served hide</span>
                    <span class="small text-muted ms-1">Next wave re-hides immediately.</span>
                @elseif($status === \App\Models\User::CATALOG_COPY_WARNED)
                    <span class="badge bg-warning text-dark">Warned</span>
                @else
                    <span class="text-muted small">No active copy strike</span>
                @endif
                <span class="small text-muted ms-2">{{ (int) ($account->catalog_copy_strike_count ?? 0) }} strikes</span>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0">
            <strong>Recent copies</strong>
            <div class="small text-muted">Last 50 recorded clipboard hosts.</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Host</th>
                            <th>Site</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($copyEvents as $event)
                            <tr>
                                <td>{{ $event->normalized_host }}</td>
                                <td class="small text-muted">{{ $event->site?->site_name ?: ($event->site_id ? '#'.$event->site_id : '—') }}</td>
                                <td class="small text-muted">{{ $event->created_at?->timezone(config('app.timezone'))->format('M j, H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No copy events on file.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0">
            <strong>Hide-mode unlocks</strong>
            <div class="small text-muted">Last 50 eye / visit / cart disclosures.</div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Site</th>
                            <th>Source</th>
                            <th>IP</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reveals as $reveal)
                            <tr>
                                <td>{{ $reveal->site?->domain ?: ($reveal->site?->site_name ?: '#'.$reveal->site_id) }}</td>
                                <td>{{ $reveal->source ?: '—' }}</td>
                                <td class="small text-muted">{{ $reveal->ip_address ?: '—' }}</td>
                                <td class="small text-muted">{{ $reveal->created_at?->timezone(config('app.timezone'))->format('M j, H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No hide-mode unlocks recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
