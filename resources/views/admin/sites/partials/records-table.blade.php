@php
    $selectedCountry = $selectedCountry ?? '';
    $missingMarket = (bool) ($missingMarket ?? false);
@endphp
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
                            @if(!empty($site['missing_market']) && !empty($site['active']))
                                <span class="badge text-bg-danger ms-1">Missing market</span>
                            @elseif(!empty($site['missing_market']))
                                <span class="badge text-bg-secondary ms-1">No country</span>
                            @endif
                        </td>
                        <td class="text-uppercase small">{{ $site['countries'] !== '' ? $site['countries'] : '—' }}</td>
                        <td class="small">{{ $site['categories'] !== '' ? $site['categories'] : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">
                            @if($missingMarket)
                                No active websites are missing a marketplace country.
                            @elseif($selectedCountry !== '')
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
    <div class="d-flex justify-content-center mt-3" data-records-pagination>
        {{ $sites->links() }}
    </div>
@endif
