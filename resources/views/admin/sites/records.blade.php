@extends('admin.layouts.app')

@section('content')
@php
    $selectedCountry = $selectedCountry ?? '';
    $countries = $countries ?? collect();
    $exportParams = $selectedCountry !== '' ? ['country' => $selectedCountry] : [];
@endphp
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Websites records sheet</h4>
            <p class="text-muted mb-0 small">
                Live from database — refreshes on every load. Columns: URL, countries, categories only.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.sites.records.export', $exportParams) }}" class="btn btn-sm btn-primary">
                <i class="fa fa-download me-1"></i> Download CSV
            </a>
            <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-left me-1"></i> Back to Sites
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.sites.records') }}" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <label for="recordsCountryFilter" class="form-label small fw-semibold mb-1">Filter by country</label>
                    <select name="country" id="recordsCountryFilter" class="form-select form-select-sm">
                        <option value="">All countries</option>
                        @foreach($countries as $country)
                            <option value="{{ strtolower($country->code) }}" @selected($selectedCountry === strtolower((string) $country->code))>
                                {{ $country->name }} ({{ strtoupper($country->code) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-sm btn-dark">
                        <i class="fa fa-filter me-1"></i> Apply
                    </button>
                    @if($selectedCountry !== '')
                        <a href="{{ route('admin.sites.records') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    @endif
                </div>
                <div class="col-12 col-md text-md-end">
                    <span class="small text-muted">
                        Showing {{ $sites->total() }} site{{ $sites->total() === 1 ? '' : 's' }}
                        @if($selectedCountry !== '')
                            in <strong class="text-uppercase">{{ $selectedCountry }}</strong>
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:16rem;">URL</th>
                        <th style="min-width:8rem;">Countries</th>
                        <th style="min-width:12rem;">Categories</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sites as $site)
                        <tr>
                            <td class="text-break">
                                @if($site['url'] !== '')
                                    <a href="{{ $site['url'] }}" target="_blank" rel="noopener noreferrer">{{ $site['url'] }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-uppercase small">{{ $site['countries'] !== '' ? $site['countries'] : '—' }}</td>
                            <td class="small">{{ $site['categories'] !== '' ? $site['categories'] : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">
                                @if($selectedCountry !== '')
                                    No websites found for country <span class="text-uppercase">{{ $selectedCountry }}</span>.
                                @else
                                    No websites found.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($sites->hasPages())
        <div class="d-flex justify-content-center mt-3">
            {{ $sites->links() }}
        </div>
    @endif
</div>
@endsection
