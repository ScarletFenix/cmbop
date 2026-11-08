@extends('admin.layouts.app')

@section('title', 'Weekly new sites')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h2 class="mb-1 fw-semibold">Weekly new-site digest</h2>
            <p class="text-muted mb-0">Personalised Monday email of newly catalogued sites matching an advertiser’s paid niche+country pairs. Default on; this switch is only this feature.</p>
        </div>
        <a href="{{ route('admin.emails.index') }}" class="btn btn-outline-secondary btn-sm">Email Center</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(!$tablesReady)
        <div class="alert alert-warning">Tables are not installed. Run <code>php artisan migrate</code> or <code>database/sql/add_weekly_new_sites_digest.sql</code> in phpMyAdmin. Do not send live mail from this environment.</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-2"><strong>Schedule:</strong> Monday {{ $time }} {{ $timezone }} (configurable). Min {{ $minSites }} sites, max {{ $maxSites }}. Current week key: <code>{{ $weekKey }}</code>.</p>
            <p class="mb-3"><strong>Watermark:</strong> {{ $watermark ? $watermark->toIso8601String() : 'not set' }} — listings first catalogued at or before this instant are never emailed as new.</p>
            <form method="POST" action="{{ route('admin.weekly-new-sites.toggle') }}" class="d-inline">
                @csrf
                <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
                <button type="submit" class="btn btn-sm {{ $enabled ? 'btn-outline-danger' : 'btn-primary' }}">
                    {{ $enabled ? 'Turn off' : 'Turn on' }}
                </button>
            </form>
            <form method="POST" action="{{ route('admin.weekly-new-sites.dry-run') }}" class="d-inline ms-2">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary" {{ $tablesReady ? '' : 'disabled' }}>Dry run (no mail)</button>
            </form>
            <a href="{{ route('admin.emails.preview', 'weekly_new_sites') }}" class="btn btn-sm btn-outline-secondary ms-2" target="_blank">Email preview</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Sites sent</div>
                <div class="fs-4">{{ number_format($report['sends']) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Clicks logged</div>
                <div class="fs-4">{{ number_format($report['clicks']) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Site-card clicks</div>
                <div class="fs-4">{{ number_format($report['site_clicks']) }}</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted small">Paid orders after a site click ({{ $report['attribution_window_days'] }}d)</div>
                <div class="fs-4">{{ number_format($report['orders_after_click']) }}</div>
                <div class="small text-muted">Observational only — not claimed as caused by the email.</div>
            </div></div>
        </div>
    </div>

    <form method="get" action="{{ route('admin.weekly-new-sites.preview') }}" class="card mb-3">
        <div class="card-body d-flex gap-2 align-items-end">
            <div class="flex-grow-1">
                <label class="form-label" for="previewEmail">Match preview (no send)</label>
                <input class="form-control" id="previewEmail" name="email" type="email" placeholder="advertiser@example.com">
            </div>
            <button class="btn btn-outline-secondary" type="submit">Preview matches</button>
        </div>
    </form>

    <h3 class="h6">Recent runs</h3>
    <div class="table-responsive mb-4">
        <table class="table table-sm">
            <thead><tr><th>ID</th><th>Week</th><th>Dry</th><th>Status</th><th>Queued</th><th>Thin</th><th>Permission</th><th>Started</th></tr></thead>
            <tbody>
            @forelse($runs as $run)
                <tr>
                    <td>{{ $run->id }}</td>
                    <td>{{ $run->week_key }}</td>
                    <td>{{ $run->dry_run ? 'yes' : 'no' }}</td>
                    <td>{{ $run->status }}</td>
                    <td>{{ $run->queued }}</td>
                    <td>{{ $run->skipped_thin }}</td>
                    <td>{{ $run->skipped_permission }}</td>
                    <td>{{ optional($run->started_at)->toDateTimeString() }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-muted">No runs yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="h6">Recent recipients</h3>
    <div class="table-responsive mb-4">
        <table class="table table-sm">
            <thead><tr><th>ID</th><th>User</th><th>Week</th><th>Status</th><th>Sites</th><th>Basis</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse($recipients as $row)
                <tr>
                    <td>{{ $row->id }}</td>
                    <td>{{ $row->user_id }}</td>
                    <td>{{ $row->week_key }}</td>
                    <td>{{ $row->status }}</td>
                    <td>{{ $row->site_count }}</td>
                    <td>{{ $row->permission_basis }}</td>
                    <td>{{ $row->skip_reason }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">No recipient rows yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="h6">Recent site sends</h3>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>ID</th><th>User</th><th>Site</th><th>Domain</th><th>Pair</th><th>Status</th><th>Price</th></tr></thead>
            <tbody>
            @forelse($sends as $send)
                <tr>
                    <td>{{ $send->id }}</td>
                    <td>{{ $send->user_id }}</td>
                    <td>{{ $send->site_id }}</td>
                    <td>{{ $send->domain }}</td>
                    <td>{{ $send->niche }} / {{ $send->country_code }}</td>
                    <td>{{ $send->status }}</td>
                    <td>{{ $send->price_eur }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted">No site sends yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
