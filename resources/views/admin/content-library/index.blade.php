@extends('admin.layouts.app')

@section('content')
@php
    $chipDefs = [
        'all' => 'All',
        'available' => 'Approved',
        'evaluating' => 'Evaluating',
        'in_progress' => 'In progress',
        'needs_fix' => 'Needs corrections',
        'completed' => 'Completed/LIVE',
        'archived' => 'Archived',
        'expired' => 'Expired',
    ];
    $filterBase = $filterQuery ?? [];
    $chipUrl = function (string $availability) use ($filterBase) {
        $params = $filterBase;
        if ($availability === 'all') {
            unset($params['availability']);
        } else {
            $params['availability'] = $availability;
        }
        unset($params['page']);

        return route('admin.content-library.index', $params);
    };
    $availabilityBadge = function ($submission): array {
        return match ($submission->libraryAvailability()) {
            'available' => ['success', 'Approved'],
            'evaluating' => ['info', 'Evaluating'],
            'in_progress' => ['primary', 'In progress'],
            'published' => ['success', 'Completed/LIVE'],
            'needs_fix' => ['danger', 'Needs corrections'],
            'expired' => ['warning', 'Expired'],
            'archived' => ['dark', 'Archived'],
            default => ['secondary', 'Pending'],
        };
    };
@endphp
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
        @if($userId)
            <input type="hidden" name="user_id" value="{{ $userId }}">
        @endif
        <input type="hidden" name="availability" value="{{ $availability }}">
        <div class="col-md-3">
            <x-slb-search-field name="q" id="adminContentLibrarySearch" :value="$search" placeholder="Title, file, email" />
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminLibraryCountry">Country</label>
            <select name="country" id="adminLibraryCountry" class="form-select form-select-sm">
                <option value="all" @selected($country === 'all')>All countries</option>
                @foreach($countries as $code)
                    <option value="{{ $code }}" @selected($country === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminLibraryLanguage">Language</label>
            <select name="language" id="adminLibraryLanguage" class="form-select form-select-sm">
                <option value="all" @selected($language === 'all')>All languages</option>
                @foreach($languages as $code)
                    <option value="{{ $code }}" @selected($language === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            <a href="{{ route('admin.content-library.index') }}" class="btn btn-sm btn-link">Reset</a>
        </div>
    </form>

    @if($filterUser)
        <div class="alert alert-light border py-2 px-3 small mb-3 d-flex flex-wrap align-items-center gap-2">
            <span>Advertiser filter:</span>
            <a href="{{ route('admin.users.index', ['user' => $filterUser->id]) }}#user-{{ $filterUser->id }}">
                {{ $filterUser->name ?: 'User #'.$filterUser->id }}
            </a>
            <span class="text-muted">{{ $filterUser->email }}</span>
            <a href="{{ route('admin.content-library.index', collect($filterQuery)->except('user_id')->all()) }}" class="ms-auto">Clear advertiser</a>
        </div>
    @endif

    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Library availability filter">
        @foreach($chipDefs as $key => $label)
            @php $count = (int) ($availabilityCounts[$key] ?? 0); @endphp
            <a href="{{ $chipUrl($key) }}"
               class="btn btn-sm {{ $availability === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                {{ $label }} ({{ $count }})
            </a>
        @endforeach
    </nav>

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
                    @php
                        [$badgeTone, $badgeLabel] = $availabilityBadge($submission);
                        $title = $submission->title ?: $submission->original_filename;
                        $showFilename = filled($submission->original_filename)
                            && strcasecmp((string) $submission->title, (string) $submission->original_filename) !== 0
                            && filled($submission->title);
                        $market = trim(strtoupper(implode('/', array_filter([
                            $submission->country,
                            $submission->language,
                        ]))));
                        $libraryOrder = $submission->libraryOrder();
                        $orderLabel = $libraryOrder?->order_number
                            ?: ($libraryOrder ? '#'.$libraryOrder->id : null);
                        $placementSite = $submission->placementItem()?->site?->site_name
                            ?: $submission->orderItem?->site?->site_name;
                    @endphp
                    <tr>
                        <td class="text-muted small">#{{ $submission->id }}</td>
                        <td>
                            <div class="fw-semibold">{{ $title }}</div>
                            @if($showFilename)
                                <div class="small text-muted">{{ $submission->original_filename }}</div>
                            @endif
                            @if($orderLabel)
                                <div class="small text-muted">
                                    Order
                                    @if($libraryOrder)
                                        <a href="{{ route('admin.orders.show', $libraryOrder->id) }}">{{ $orderLabel }}</a>
                                    @else
                                        {{ $orderLabel }}
                                    @endif
                                    @if($placementSite)
                                        · {{ $placementSite }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($submission->user)
                                <div>
                                    <a href="{{ route('admin.users.index', ['user' => $submission->user->id]) }}#user-{{ $submission->user->id }}">
                                        {{ $submission->user->name ?: 'User #'.$submission->user->id }}
                                    </a>
                                </div>
                                <div class="small text-muted">{{ $submission->user->email }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($market !== '')
                                <span class="badge text-bg-light">{{ $market }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $badgeTone }}">{{ $badgeLabel }}</span>
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
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.content-library.show', array_merge(['submission' => $submission], $filterQuery)) }}">View</a>
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
