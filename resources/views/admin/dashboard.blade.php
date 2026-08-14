@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">

    @include('admin.partials.page-header', [
        'title' => 'Admin Dashboard',
        'subtitle' => 'Platform overview, money flow, and items that need your attention.',
    ])

    {{-- Moderation being off changes nothing visible anywhere else: articles are
         approved, orders go through, and the scan log fills with passes. Nobody
         visits the moderation screen to check something they believe is running,
         so it has to say so here. --}}
    @php
        $moderationOff = ! app(\App\Services\ContentModeration\ContentModerationService::class)->isEnabled();
    @endphp
    @if($moderationOff)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>Content moderation is switched off.</strong>
                No article is being scanned, so casino, adult and every other restricted
                category is passing straight through to checkout.
                <a href="{{ route('admin.moderation.index') }}" class="alert-link">Turn it back on</a>.
            </div>
        </div>
    @endif

    <!-- KPI cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Users</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiUsers">—</h3>
                        <span class="badge bg-primary-subtle text-primary" id="kpiUsers7d">+0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiAdvertisers">0</span> advertisers ·
                        <span id="kpiPublishers">0</span> publishers
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">GMV (paid orders)</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiRevenue">—</h3>
                        <span class="badge bg-success-subtle text-success" id="kpiRevenue7d">€0 / 7d</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiPaidOrders">0</span> paid orders
                        · <a href="{{ route('admin.finance') }}" class="link-secondary">Margin &amp; wallets</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Sites</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiSites">—</h3>
                        <span class="badge bg-warning-subtle text-warning" id="kpiUnverified">0 in review</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiVerified">0</span> live in catalog
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Needs Attention</div>
                    <div class="d-flex align-items-end justify-content-between">
                        <h3 class="mb-0" id="kpiAttention">—</h3>
                        <span class="badge bg-danger-subtle text-danger">Action queue</span>
                    </div>
                    <div class="small text-muted mt-2">
                        <span id="kpiDeposits">0</span> deposits ·
                        <span id="kpiWithdrawals">0</span> withdrawals ·
                        <span id="kpiPayments">0</span> payments
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action queues (first viewport priority) -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-wallet me-2 text-success"></i>Pending Deposits</strong>
                    <a href="{{ route('admin.deposits') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueDeposits">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-money-bill-wave me-2 text-warning"></i>Pending Withdrawals</strong>
                    <a href="{{ route('admin.withdrawals') }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueWithdrawals">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-globe me-2 text-primary"></i>Sites Awaiting Verify</strong>
                    <a href="{{ route('admin.sites.index', ['needs_review' => 1]) }}" class="small">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Site</th><th>Publisher</th><th>Date</th></tr>
                            </thead>
                            <tbody id="queueSites">
                                <tr><td colspan="3" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Orders the reminder cadence could not rescue. Hidden entirely when the
         queue is empty so an untouched panel is not a permanent fixture. --}}
    <div class="row g-3 mb-4 d-none" id="stalledOrdersRow">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-triangle-exclamation me-2 text-danger"></i>Stalled orders <span class="badge text-bg-danger ms-1" id="stalledOrdersCount">0</span></strong>
                    <span class="text-muted small">Every reminder sent, no response. Chase again or refund the advertiser.</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order</th>
                                    <th>Site</th>
                                    <th>Publisher</th>
                                    <th>Advertiser</th>
                                    <th>Problem</th>
                                    <th>Late by</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="queueStalled">
                                <tr><td colspan="7" class="text-center text-muted py-3">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong><i class="fa fa-chart-line me-2 text-primary"></i>Revenue &amp; Orders (30 days)</strong>
                    <span class="text-muted small">Paid revenue vs order volume</span>
                </div>
                <div class="card-body">
                    <canvas id="trendChart" height="110"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-user-plus me-2 text-success"></i>New Signups (30 days)</strong>
                </div>
                <div class="card-body">
                    <canvas id="signupChart" height="110"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-shopping-cart me-2 text-info"></i>Orders by Status</strong>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <canvas id="orderStatusChart" style="max-height:260px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-users me-2 text-secondary"></i>Users by Role</strong>
                </div>
                <div class="card-body d-flex justify-content-center">
                    <canvas id="roleChart" style="max-height:260px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Promotions widget (below attention work) -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="text-muted small mb-1"><i class="fa fa-bullhorn me-1 text-primary"></i>Promotions Center</div>
                            <h5 class="mb-1">Announcements &amp; Ad Banners</h5>
                            <p class="text-muted mb-0 small">
                                Control discounts, platform changes, and sized website banners from one place.
                            </p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline-secondary btn-sm">
                                Open Promotions
                            </a>
                        </div>
                    </div>
                    @php
                        $promoStats = app(\App\Services\PromotionService::class)->dashboardStats();
                    @endphp
                    <div class="row g-3 mt-2">
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live announcements</div>
                                <div class="fs-4 fw-semibold">{{ $promoStats['announcements_live'] }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Live banners</div>
                                <div class="fs-4 fw-semibold">{{ $promoStats['banners_live'] }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner impressions</div>
                                <div class="fs-4 fw-semibold">{{ number_format($promoStats['banner_impressions']) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="small text-muted">Banner clicks</div>
                                <div class="fs-4 fw-semibold">{{ number_format($promoStats['banner_clicks']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const money = (n) => '€' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n) => Number(n || 0).toLocaleString();

let trendChart, signupChart, orderStatusChart, roleChart;

async function dashboardFetch(url) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    });
    let json = null;
    try {
        json = await res.json();
    } catch (e) {
        json = null;
    }
    if (!res.ok || !json || !json.success) {
        const message = (json && json.message) ? json.message : 'Could not load this panel';
        if (window.showAppToast) {
            window.showAppToast(message, 'error');
        }
        throw new Error(message);
    }
    return json;
}

function retryRow(cols, loaderName) {
    return `<tr><td colspan="${cols}" class="text-center text-muted py-3">
        Couldn’t load —
        <button type="button" class="btn btn-link btn-sm p-0 align-baseline js-dashboard-retry" data-loader="${escapeHtml(loaderName)}">retry</button>
    </td></tr>`;
}

function makeChart(existing, canvasId, config) {
    if (existing) {
        existing.destroy();
    }
    if (typeof Chart === 'undefined') {
        throw new Error('Charts unavailable');
    }
    return new Chart(document.getElementById(canvasId), config);
}

async function loadStatistics() {
    const json = await dashboardFetch(`{{ route('admin.dashboard.statistics') }}`);
    const d = json.data;

    document.getElementById('kpiUsers').textContent = num(d.total_users);
    document.getElementById('kpiUsers7d').textContent = '+' + num(d.new_users_7d) + ' / 7d';
    document.getElementById('kpiAdvertisers').textContent = num(d.advertisers);
    document.getElementById('kpiPublishers').textContent = num(d.publishers);
    document.getElementById('kpiRevenue').textContent = money(d.revenue);
    document.getElementById('kpiRevenue7d').textContent = money(d.revenue_7d) + ' / 7d';
    document.getElementById('kpiPaidOrders').textContent = num(d.paid_orders);
    document.getElementById('kpiSites').textContent = num(d.total_sites);
    document.getElementById('kpiVerified').textContent = num(d.live_sites ?? d.verified_sites);
    document.getElementById('kpiUnverified').textContent = num(d.unverified_sites) + ' in review';
    document.getElementById('kpiDeposits').textContent = num(d.pending_deposits);
    document.getElementById('kpiWithdrawals').textContent = num(d.pending_withdrawals);
    document.getElementById('kpiPayments').textContent = num(d.pending_payments);
    document.getElementById('kpiAttention').textContent = num(d.needs_attention);
}

async function loadTrends() {
    const json = await dashboardFetch(`{{ route('admin.dashboard.trends') }}?days=30`);

    const commonOpts = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { display: true, position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    };

    trendChart = makeChart(trendChart, 'trendChart', {
        type: 'line',
        data: {
            labels: json.labels,
            datasets: [
                {
                    label: 'Revenue (€)',
                    data: json.revenue,
                    borderColor: '#1a585e',
                    backgroundColor: 'rgba(26, 88, 94, 0.12)',
                    fill: true,
                    tension: 0.35,
                    yAxisID: 'y'
                },
                {
                    label: 'Orders',
                    data: json.orders,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14, 165, 233, 0.08)',
                    fill: false,
                    tension: 0.35,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            ...commonOpts,
            scales: {
                y:  { beginAtZero: true, position: 'left', title: { display: true, text: 'Revenue (€)' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Orders' } }
            }
        }
    });

    signupChart = makeChart(signupChart, 'signupChart', {
        type: 'bar',
        data: {
            labels: json.labels,
            datasets: [{
                label: 'New users',
                data: json.signups,
                backgroundColor: 'rgba(26, 88, 94, 0.75)',
                borderRadius: 4
            }]
        },
        options: {
            ...commonOpts,
            plugins: { legend: { display: false } }
        }
    });
}

async function loadDistributions() {
    const json = await dashboardFetch(`{{ route('admin.dashboard.distributions') }}`);

    const palette = ['#1a585e', '#0ea5e9', '#3faeb2', '#75787B', '#0f766e', '#b8e4e4', '#94a3b8'];

    orderStatusChart = makeChart(orderStatusChart, 'orderStatusChart', {
        type: 'doughnut',
        data: {
            labels: json.orders.labels,
            datasets: [{
                data: json.orders.values,
                backgroundColor: palette
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    roleChart = makeChart(roleChart, 'roleChart', {
        type: 'doughnut',
        data: {
            labels: json.roles.labels,
            datasets: [{
                data: json.roles.values,
                backgroundColor: ['#1a585e', '#0ea5e9', '#75787B']
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });
}

function emptyRow(cols, msg) {
    return `<tr><td colspan="${cols}" class="text-center text-muted py-3">${escapeHtml(msg)}</td></tr>`;
}

function escapeHtml(str) {
    if (str == null || str === '') return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function loadActionQueue() {
    const depBody = document.getElementById('queueDeposits');
    const wBody = document.getElementById('queueWithdrawals');
    const sBody = document.getElementById('queueSites');

    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.action-queue') }}`);

        if (!json.deposits.length) {
            depBody.innerHTML = emptyRow(3, 'No pending deposits');
        } else {
            depBody.innerHTML = json.deposits.map(d => `
                <tr>
                    <td>
                        <div class="fw-semibold">${escapeHtml(d.user)}</div>
                        <div class="small text-muted">${escapeHtml(d.email || '')}</div>
                    </td>
                    <td>${money(d.amount)}</td>
                    <td class="small text-muted">${escapeHtml(d.date)}</td>
                </tr>`).join('');
        }

        if (!json.withdrawals.length) {
            wBody.innerHTML = emptyRow(3, 'No pending withdrawals');
        } else {
            wBody.innerHTML = json.withdrawals.map(w => `
                <tr>
                    <td>
                        <div class="fw-semibold">${escapeHtml(w.user)}</div>
                        <div class="small text-muted">${escapeHtml(w.email || '')}${w.status && w.status !== 'pending' ? ' · ' + escapeHtml(w.status) : ''}</div>
                    </td>
                    <td>${money(w.amount)}</td>
                    <td class="small text-muted">${escapeHtml(w.date)}</td>
                </tr>`).join('');
        }

        if (!json.sites.length) {
            sBody.innerHTML = emptyRow(3, 'No sites awaiting verification');
        } else {
            sBody.innerHTML = json.sites.map(s => `
                <tr>
                    <td>
                        <div class="fw-semibold">${escapeHtml(s.site_name || '—')}</div>
                        <div class="small text-muted text-truncate" style="max-width:140px;">${escapeHtml(s.site_url || '')}</div>
                    </td>
                    <td>${escapeHtml(s.publisher)}</td>
                    <td class="small text-muted">${escapeHtml(s.date)}</td>
                </tr>`).join('');
        }
    } catch (err) {
        depBody.innerHTML = retryRow(3, 'loadActionQueue');
        wBody.innerHTML = retryRow(3, 'loadActionQueue');
        sBody.innerHTML = retryRow(3, 'loadActionQueue');
        throw err;
    }
}

async function loadStalledOrders() {
    try {
        const json = await dashboardFetch(`{{ route('admin.dashboard.stalled-orders') }}`);
        if (!json.items.length) return;

        document.getElementById('stalledOrdersRow').classList.remove('d-none');
        document.getElementById('stalledOrdersCount').textContent = json.count;

        document.getElementById('queueStalled').innerHTML = json.items.map(i => `
            <tr>
                <td class="fw-semibold">#${escapeHtml(i.order_number)}</td>
                <td>${escapeHtml(i.site_name)}</td>
                <td>
                    <div>${escapeHtml(i.publisher)}</div>
                    <div class="small text-muted">${escapeHtml(i.publisher_email || '')}</div>
                </td>
                <td>${escapeHtml(i.advertiser)}</td>
                <td><span class="badge text-bg-warning">${i.track === 'accept' ? 'Not accepted' : 'Not published'}</span></td>
                <td>
                    <div>${escapeHtml(i.late_label || (i.days_overdue + ' day(s)'))}</div>
                    <div class="small text-muted">${i.last_reminded_at ? 'Reminded ' + escapeHtml(i.last_reminded_at) : ''}</div>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary js-remind-publisher" data-item="${i.order_item_id}">
                        Remind now
                    </button>
                </td>
            </tr>`).join('');
    } catch (err) {
        document.getElementById('stalledOrdersRow').classList.remove('d-none');
        document.getElementById('queueStalled').innerHTML = retryRow(7, 'loadStalledOrders');
        throw err;
    }
}

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-remind-publisher');
    if (!btn) return;

    btn.disabled = true;
    btn.classList.add('is-loading');

    try {
        const res = await fetch(`{{ url('admin/orders/items') }}/${btn.dataset.item}/remind-publisher`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
        });
        const json = await res.json();
        btn.classList.remove('is-loading');

        if (json.success) {
            // A disabled button renders grey whatever colour class it carries, so
            // the confirmation is plain text rather than a button that looks
            // switched off at the moment it succeeded.
            btn.outerHTML = '<span class="text-success small fw-semibold">'
                + '<i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Reminder sent</span>';
        } else {
            btn.disabled = false;
            btn.textContent = 'Retry';
        }

        if (window.showAppToast) {
            window.showAppToast(json.message || (json.success ? 'Reminder sent' : 'Could not send the reminder'), json.success ? 'success' : 'error');
        }
    } catch (err) {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        btn.textContent = 'Retry';
        if (window.showAppToast) {
            window.showAppToast('Could not send the reminder', 'error');
        }
    }
});

const dashboardLoaders = {
    loadStatistics,
    loadTrends,
    loadDistributions,
    loadActionQueue,
    loadStalledOrders,
};

document.addEventListener('click', (e) => {
    const retry = e.target.closest('.js-dashboard-retry');
    if (!retry) return;
    const loader = dashboardLoaders[retry.dataset.loader];
    if (typeof loader === 'function') {
        loader().catch(err => console.error('Dashboard retry failed', err));
    }
});

Promise.all([loadStatistics(), loadTrends(), loadDistributions(), loadActionQueue(), loadStalledOrders()])
    .catch(err => console.error('Dashboard load failed', err));
</script>
@endsection
