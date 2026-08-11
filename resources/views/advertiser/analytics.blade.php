@extends('advertiser.layouts.app')

@section('content')
@php
    $a = $analytics;
    $hasSpend = $a['has_spend'];
    $view = $view ?? 'day';
    $summary = $a['summary'] ?? [];
    $dimension = $dimension ?? 'payment_method';
    $budgetStatus = $budgetStatus ?? ['has_budget' => false];
@endphp

<link href="{{ asset('assets/css/advertiser-analytics.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-analytics.css')) ?: '1' }}" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<div class="an-page">
    <div class="an-hero d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <h2>Spending History</h2>
            <p class="mb-0">Solid bars are completed spend. Dim bars are paid orders still in progress — they become spent when the order completes.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('advertiser.analytics.export-csv', request()->only(['from','to'])) }}">Export CSV</a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('advertiser.analytics.export-pdf', request()->only(['from','to'])) }}">Export PDF</a>
        </div>
    </div>

    <div class="an-summary mb-3">
        <div>
            <span class="label">Net spend</span>
            <span class="value">€{{ number_format((float) ($summary['net'] ?? $a['total_spend'] ?? 0), 2) }}</span>
        </div>
        <div>
            <span class="label">Gross</span>
            <span class="value">€{{ number_format((float) ($summary['gross'] ?? 0), 2) }}</span>
        </div>
        <div>
            <span class="label">Refunded</span>
            <span class="value">€{{ number_format((float) ($summary['refunded'] ?? 0), 2) }}</span>
        </div>
        <div>
            <span class="label">Spent (completed)</span>
            <span class="value">€{{ number_format((float) ($summary['spent'] ?? 0), 2) }}</span>
        </div>
        <div>
            <span class="label">In progress</span>
            <span class="value">€{{ number_format((float) ($summary['in_progress'] ?? 0), 2) }}</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            @unless($hasSpend)
                <div class="an-empty">
                    <h3>No spending history yet</h3>
                    <p>Place your first order to start tracking spend over time.</p>
                    <a href="{{ route('advertiser.catalog') }}" class="btn btn-primary">Browse Websites</a>
                </div>
            @else
                <div class="an-card">
                    <div class="an-toolbar">
                        <h5 class="mb-0">Spend over time</h5>
                        <div class="an-toggle" role="group" aria-label="Chart view">
                            <a href="{{ route('advertiser.analytics', array_merge(request()->only(['from','to','breakdown']), ['view' => 'order'])) }}"
                               class="{{ $view === 'order' ? 'active' : '' }}">By order</a>
                            <a href="{{ route('advertiser.analytics', array_merge(request()->only(['from','to','breakdown']), ['view' => 'day'])) }}"
                               class="{{ $view === 'day' ? 'active' : '' }}">By day</a>
                            <a href="{{ route('advertiser.analytics', array_merge(request()->only(['from','to','breakdown']), ['view' => 'month'])) }}"
                               class="{{ $view === 'month' ? 'active' : '' }}">By month</a>
                        </div>
                    </div>
                    <div class="an-chart-wrap">
                        <canvas id="spendChart" height="120"></canvas>
                    </div>
                    <p class="an-hint" id="chartHint"></p>
                </div>
            @endunless
        </div>
        <div class="col-lg-4">
            <div class="an-card mb-3">
                <h5 class="mb-3">Monthly budget</h5>
                <form method="POST" action="{{ route('advertiser.analytics.budget') }}">
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Monthly limit (€)</label>
                        <input type="number" step="0.01" min="0" name="monthly_limit" class="form-control form-control-sm"
                               value="{{ old('monthly_limit', $budget?->monthly_limit) }}" placeholder="e.g. 500">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Warn at %</label>
                        <input type="number" min="1" max="100" name="warn_at_percent" class="form-control form-control-sm"
                               value="{{ old('warn_at_percent', $budget?->warn_at_percent ?? 80) }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Low balance alert (€)</label>
                        <input type="number" step="0.01" min="0" name="low_balance_threshold" class="form-control form-control-sm"
                               value="{{ old('low_balance_threshold', $budget?->low_balance_threshold) }}" placeholder="e.g. 50">
                    </div>
                    <div class="form-check mb-1">
                        <input type="hidden" name="notify_bell" value="0">
                        <input class="form-check-input" type="checkbox" name="notify_bell" value="1" id="notifyBell"
                               @checked(old('notify_bell', $budget?->notify_bell ?? true))>
                        <label class="form-check-label small" for="notifyBell">Bell alerts</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="hidden" name="notify_email" value="0">
                        <input class="form-check-input" type="checkbox" name="notify_email" value="1" id="notifyEmail"
                               @checked(old('notify_email', $budget?->notify_email ?? true))>
                        <label class="form-check-label small" for="notifyEmail">Email alerts</label>
                    </div>
                    <button class="btn btn-sm btn-primary w-100" type="submit">Save budget</button>
                </form>
                @if(!empty($budgetStatus['monthly_limit']))
                    <p class="small text-muted mt-3 mb-0">
                        This month committed:
                        <strong>€{{ number_format((float) $budgetStatus['committed'], 2) }}</strong>
                        / €{{ number_format((float) $budgetStatus['monthly_limit'], 2) }}
                        ({{ number_format((float) $budgetStatus['percent'], 1) }}%)
                    </p>
                @endif
            </div>

            <div class="an-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Breakdown</h5>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-3">
                    @foreach(['payment_method' => 'Method', 'country' => 'Country', 'category' => 'Category', 'site' => 'Site', 'sensitive' => 'Sensitive'] as $key => $label)
                        <a class="btn btn-sm {{ $dimension === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                           href="{{ route('advertiser.analytics', array_merge(request()->only(['view','from','to']), ['breakdown' => $key])) }}">{{ $label }}</a>
                    @endforeach
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($breakdown as $row)
                                <tr>
                                    <td class="small">{{ $row['label'] }}</td>
                                    <td class="text-end small">€{{ number_format((float) $row['net'], 2) }}</td>
                                    <td class="text-end small">{{ $row['orders'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted small">No breakdown yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($hasSpend)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const view = @json($view);
    const rows = @json($a['series'] ?? []);
    const labels = rows.map(r => r.short_label || r.label);
    const spent = rows.map(r => Number(r.spent || 0));
    const inProgress = rows.map(r => Number(r.in_progress || 0));

    document.getElementById('chartHint').textContent =
        'Solid = completed spend. Dim = paid orders still processing — they move to spent when completed (same day/month).';

    const ctx = document.getElementById('spendChart');
    if (!ctx) return;

    function money(n) {
        const v = Number(n || 0);
        return v % 1 === 0 ? ('€' + v.toFixed(0)) : ('€' + v.toFixed(2));
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Spent (completed)',
                    data: spent,
                    backgroundColor: 'rgba(26, 88, 94, 0.88)',
                    hoverBackgroundColor: 'rgba(26, 88, 94, 1)',
                    borderRadius: 6,
                    maxBarThickness: 52,
                    stack: 'spend',
                },
                {
                    label: 'In progress (will add when completed)',
                    data: inProgress,
                    backgroundColor: 'rgba(26, 88, 94, 0.28)',
                    hoverBackgroundColor: 'rgba(63, 174, 178, 0.55)',
                    borderColor: 'rgba(26, 88, 94, 0.35)',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 52,
                    stack: 'spend',
                },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            layout: { padding: { top: 16 } },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const row = rows[items[0].dataIndex];
                            if (!row) return '';
                            if (view === 'order') {
                                return (row.short_label || row.label)
                                    + (row.website && row.website !== '—' ? ' · ' + row.website : '');
                            }
                            return row.label;
                        },
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            if (item.datasetIndex === 0) {
                                return money(item.raw) + ' spent'
                                    + (view !== 'order' ? ' (' + (row?.spent_orders || 0) + ' completed)' : '');
                            }
                            if (!item.raw) return null;
                            return money(item.raw) + ' in progress — adds to spent when completed'
                                + (view !== 'order' ? ' (' + (row?.in_progress_orders || 0) + ' orders)' : '');
                        }
                    }
                }
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: (v) => '€' + v
                    }
                }
            }
        }
    });
});
</script>
@endif
@endsection
