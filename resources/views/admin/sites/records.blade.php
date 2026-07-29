@extends('admin.layouts.app')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Websites records sheet</h4>
            <p class="text-muted mb-0 small">
                Live from database — refreshes on every load. Columns: URL, countries, categories only.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.sites.records.export') }}" class="btn btn-sm btn-primary">
                <i class="fa fa-download me-1"></i> Download CSV
            </a>
            <a href="{{ route('admin.sites.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-left me-1"></i> Back to Sites
            </a>
        </div>
    </div>

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
                            <td colspan="3" class="text-center text-muted py-4">No websites found.</td>
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
