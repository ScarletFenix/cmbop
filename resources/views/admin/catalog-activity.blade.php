@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => 'Catalog activity',
        'subtitle' => 'Who is opening publisher addresses, and whether it looks like shopping.',
    ])

    @if(! $available)
        <div class="alert alert-warning">
            Domain disclosures are not being recorded yet — run migrations to create
            <code>site_url_reveals</code>.
        </div>
    @else
        @unless($enforcing)
            <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
                <i class="fa fa-circle-info mt-1" aria-hidden="true"></i>
                <div>
                    <strong>Watching only.</strong>
                    Pace checks are recording and reporting but never restricting anyone.
                    Leave it this way until this table tells you what normal looks like here,
                    then set the thresholds from your own data rather than from a guess.
                </div>
            </div>
        @endunless

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong>Top 50 by addresses opened &middot; last {{ $days }} days</strong>
                <div class="btn-group btn-group-sm">
                    @foreach([1, 7, 30] as $option)
                        <a href="{{ route('admin.catalog-activity', ['days' => $option]) }}"
                           class="btn btn-outline-secondary {{ $days === $option ? 'active' : '' }}">
                            {{ $option }}d
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Account</th>
                                <th class="text-end">Opened</th>
                                <th class="text-end">Last hour</th>
                                <th class="text-end">Paid orders</th>
                                <th class="text-end">Per order</th>
                                <th>Signal</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $row['user']->name ?: '—' }}</div>
                                        <div class="small text-muted">{{ $row['user']->email }}</div>
                                        <div class="small text-muted">{{ $row['account_age_days'] }} days old</div>
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($row['total']) }}</td>
                                    <td class="text-end">{{ number_format($row['last_hour']) }}</td>
                                    <td class="text-end">{{ number_format($row['orders']) }}</td>
                                    <td class="text-end">
                                        {{-- A high number here with no orders is the shape worth looking at. --}}
                                        {{ $row['per_order'] !== null ? $row['per_order'] : '—' }}
                                    </td>
                                    <td>
                                        @if($row['exempt'])
                                            <span class="badge bg-light text-dark">Exempt</span>
                                        @elseif($row['metronomic'])
                                            <span class="badge bg-warning text-dark">
                                                <i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Even timing
                                            </span>
                                        @elseif($row['orders'] === 0 && $row['total'] >= 100)
                                            <span class="badge bg-warning text-dark">
                                                <i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>No orders
                                            </span>
                                        @else
                                            <span class="text-muted small">Looks normal</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <form method="POST"
                                              action="{{ route('admin.catalog-activity.exempt', $row['user']->id) }}"
                                              class="d-inline"
                                              data-slb-confirm="{{ $row['exempt']
                                                    ? 'Put this account back under the usual pace checks?'
                                                    : 'Exempt this account from pace checks? Use this for agencies you know browse heavily.' }}"
                                              data-slb-confirm-title="{{ $row['exempt'] ? 'Remove exemption?' : 'Exempt account?' }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                {{ $row['exempt'] ? 'Remove exemption' : 'Mark as trusted' }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        Nobody has opened a publisher address in this period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
