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

    <form method="GET" action="{{ route('admin.content-library.index') }}" id="adminLibraryFilterForm" class="row g-2 align-items-end mb-3">
        @if($userId)
            <input type="hidden" name="user_id" value="{{ $userId }}">
        @endif
        <input type="hidden" name="availability" value="{{ $availability }}" id="adminLibraryAvailability">
        <div class="col-md-3">
            <x-slb-search-field name="q" id="adminContentLibrarySearch" :value="$search" placeholder="Title, file, email" />
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1" for="adminLibraryAdvertiser">Advertiser</label>
            <input type="search" name="advertiser" id="adminLibraryAdvertiser" class="form-control form-control-sm"
                   value="{{ $advertiserQuery ?? '' }}" placeholder="Name or email" autocomplete="off">
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
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1" for="adminLibrarySort">Sort</label>
            <select name="sort" id="adminLibrarySort" class="form-select form-select-sm">
                <option value="latest" @selected(($sort ?? 'latest') === 'latest')>Newest</option>
                <option value="title" @selected(($sort ?? '') === 'title')>Title</option>
                <option value="expires" @selected(($sort ?? '') === 'expires')>Expiry</option>
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
            <a href="{{ route('admin.content-library.index', collect($filterQuery)->except(['user_id', 'advertiser'])->all()) }}" class="ms-auto">Clear advertiser</a>
        </div>
    @elseif(!empty($advertiserUnmatched))
        <div class="alert alert-light border py-2 px-3 small mb-3 d-flex flex-wrap align-items-center gap-2">
            <span>No advertiser matched “{{ $advertiserQuery }}”.</span>
            <a href="{{ route('admin.content-library.index', collect($filterQuery)->except(['user_id', 'advertiser'])->all()) }}" class="ms-auto">Clear advertiser</a>
        </div>
    @endif

    <div id="adminLibraryLiveRegion">
        @include('admin.content-library.results')
    </div>
</div>
@endsection

@push('scripts')
@if(!empty($liveSearchEnabled))
<script>
window.AdminLibraryBoot = {
    resultsUrl: @json(route('admin.content-library.results', absolute: false)),
    indexUrl: @json(route('admin.content-library.index', absolute: false)),
};
</script>
<script src="{{ asset('assets/js/admin-content-library.js') }}?v={{ @filemtime(public_path('assets/js/admin-content-library.js')) ?: '1' }}" defer></script>
@endif
@endpush
