@extends('advertiser.layouts.app')

@section('title', 'Dashboard')

@push('page-styles')
    <link href="{{ asset('assets/css/advertiser-dashboard.css') }}?v={{ @filemtime(public_path('assets/css/advertiser-dashboard.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')

@php
    $stats = $stats ?? [
        'total' => 0,
        'completed' => 0,
        'in_progress' => 0,
        'cancelled' => 0,
        'needs_review' => 0,
        'needs_action' => 0,
        'awaiting_payment' => 0,
        'waiting_on_publisher' => 0,
    ];
    $recentOrders = $recentOrders ?? collect();
    $recommendedSites = $recommendedSites ?? collect();
    $hasOrderableArticle = (bool) ($hasOrderableArticle ?? false);
    $isNewAdvertiser = (bool) ($isNewAdvertiser ?? false);
    $browseCatalogUrl = route('advertiser.catalog');
    $guidedFlowUrl = route('advertiser.wizard.start');
    $needsAction = (int) ($stats['needs_action'] ?? 0);
    $awaitingPayment = (int) ($stats['awaiting_payment'] ?? 0);
    $upcomingScheduledCount = (int) ($upcomingScheduledCount ?? 0);
    $primaryAction = (string) ($primaryAction ?? 'catalog');
    $welcomeSituation = (string) ($welcomeSituation ?? '');
    $dashboardFailed = (bool) ($dashboardFailed ?? false);
    $statsUnavailable = (bool) ($statsUnavailable ?? false) || $dashboardFailed;
    $recentUnavailable = (bool) ($recentUnavailable ?? false) || $dashboardFailed;
    $walletUnavailable = (bool) ($walletUnavailable ?? false);
    $spendUnavailable = (bool) ($spendUnavailable ?? false) || $dashboardFailed;
    $spendChartUnavailable = (bool) ($spendChartUnavailable ?? false) || $dashboardFailed;
    $kpisUnavailable = $statsUnavailable;
    $numbersFailed = $dashboardFailed || $statsUnavailable;
    if ($dashboardFailed) {
        $isNewAdvertiser = false;
    }
    $wallet = $wallet ?? ['spendable' => 0, 'available' => 0, 'bonus' => 0, 'currency' => 'EUR'];
    $budgetStatus = $budgetStatus ?? ['has_budget' => false, 'low_balance' => false];
    $spendSummary = $spendSummary ?? ['net' => 0, 'spent' => 0, 'in_progress' => 0];
    $spendCandles = $spendCandles ?? ['has_spend' => false, 'series' => []];
    $supportTelegramUrl = config('services.support.telegram_url', 'https://t.me/arslan_seolinkbuildings');
    $urlVisibility = app(\App\Services\Catalog\SiteUrlVisibility::class);
@endphp

{{-- Styles: public/assets/css/advertiser-dashboard.css --}}
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div>
        <h2 class="mb-1 fw-semibold">Dashboard</h2>
        <p class="text-muted mb-0">
            Welcome back, {{ auth()->user()->name }}@if($welcomeSituation !== '') — {{ ucfirst($welcomeSituation) }}@endif.
        </p>
    </div>
    @if($numbersFailed && ! $isNewAdvertiser)
        <a href="{{ route('advertiser.dashboard') }}" class="dash-primary-cta" id="dashPrimaryCta">
            <i class="fa fa-rotate"></i> Try again
        </a>
    @elseif($isNewAdvertiser)
        {{-- Get-started panel below is the CTA --}}
    @elseif($primaryAction === 'needs_action')
        <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="dash-primary-cta" id="dashPrimaryCta">
            <i class="fa fa-clipboard-check"></i> Open orders
        </a>
    @elseif($primaryAction === 'awaiting_payment')
        <a href="{{ route('advertiser.orders', ['status' => 'awaiting_payment']) }}" class="dash-primary-cta" id="dashPrimaryCta">
            <i class="fa fa-credit-card"></i> Complete payment
        </a>
    @elseif($primaryAction === 'scheduled')
        <a href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}" class="dash-primary-cta" id="dashPrimaryCta">
            <i class="fa fa-calendar"></i> Upcoming scheduled
        </a>
    @else
        <a href="{{ $browseCatalogUrl }}" class="dash-primary-cta" id="dashPrimaryCta">
            <i class="fa fa-store"></i> Browse catalog
        </a>
    @endif
</div>

@if($numbersFailed && ! $isNewAdvertiser)
    <div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4" role="status">
        <div>
            <strong>We could not refresh your numbers</strong>
            <span class="d-block small text-muted mb-0">Try again, or open the catalog if you still want to browse.</span>
        </div>
        <a href="{{ $browseCatalogUrl }}" class="btn btn-sm btn-outline-secondary">Browse catalog</a>
    </div>
@endif

@if($isNewAdvertiser)
    @include('advertiser.partials.dashboard-wallet-strip')
    <div class="row g-4 dash-page-end">
        <div class="col-lg-7">
            <div class="dash-panel h-100">
                <h5 class="mb-1">Get started</h5>
                <p class="text-muted small mb-3">Pick publishers from the live catalog, assign an approved article in your cart, then pay.</p>
                <a href="{{ $browseCatalogUrl }}" class="get-started-cta w-100 justify-content-center mb-3">
                    <i class="fa fa-store"></i> Browse catalog
                </a>
                <p class="small text-muted text-center mb-2">
                    Prefer a guided flow?
                    <a href="{{ $guidedFlowUrl }}">Start guided placement</a>
                </p>
                <p class="small text-muted text-center mb-0">
                    <a href="{{ route('advertiser.content-library') }}">Content Library</a>
                    — upload articles before checkout
                </p>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-panel h-100 mb-3">
                <h6 class="mb-1">Recommended for you</h6>
                <p class="small text-muted mb-3">Top verified placements to start with.</p>
                @if($recommendedSites->isEmpty())
                    <x-ui.empty-state
                        class="py-2"
                        icon="fa-store"
                        title="Explore live inventory"
                        message="Open the catalog to find verified publishers for your first placement."
                        primary-label="Browse catalog"
                        :primary-url="route('advertiser.catalog')"
                    />
                @else
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @include('advertiser.partials.dashboard-recommended-site', [
                                'site' => $site,
                                'urlVisibility' => $urlVisibility,
                                'showLanguage' => true,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="help-secondary">
                <h6 class="mb-2">Need a hand?</h6>
                <p class="small text-muted mb-3">Message your client manager if you get stuck on catalog or checkout.</p>
                <a href="{{ $supportTelegramUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                    <i class="fa fa-message me-1" aria-hidden="true"></i> Start chat
                </a>
            </div>
        </div>
    </div>
@else
<div class="dash-command-surface mb-4 dash-page-end">
    @include('advertiser.partials.dashboard-wallet-strip')

    <!-- KPIs: Active is completed + in progress + review + scheduled — not unpaid/cancelled. -->
    <div class="row g-3 mb-4 px-1 pt-1">
        <div class="col-6 col-lg-3">
            <a href="{{ route('advertiser.orders') }}" class="kpi-tile">
                <div class="kpi-icon" style="background:#3faeb2;color:#fff;"><i class="fa-solid fa-box-open" aria-hidden="true"></i></div>
                <div>
                    <span class="kpi-label">Active</span>
                    <div class="kpi-value">{{ $kpisUnavailable ? '—' : $stats['total'] }}</div>
                    @if($kpisUnavailable)<span class="small text-muted">Unavailable</span>@endif
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('advertiser.orders', $needsAction > 0 ? ['status' => 'needs_action'] : []) }}" class="kpi-tile">
                <div class="kpi-icon {{ $needsAction > 0 ? '' : 'is-muted' }}"
                     style="background:{{ $needsAction > 0 ? '#d97706' : '#e2e8f0' }};color:{{ $needsAction > 0 ? '#fff' : '#64748b' }};">
                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                </div>
                <div>
                    <span class="kpi-label">Needs you</span>
                    <div class="kpi-value">{{ $kpisUnavailable ? '—' : $needsAction }}</div>
                    @if($kpisUnavailable)<span class="small text-muted">Unavailable</span>@endif
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-tile">
                <div class="kpi-icon {{ ((int) ($stats['waiting_on_publisher'] ?? 0) > 0) ? '' : 'is-muted' }}"
                     style="background:{{ ((int) ($stats['waiting_on_publisher'] ?? 0) > 0) ? '#0ea5e9' : '#e2e8f0' }};color:{{ ((int) ($stats['waiting_on_publisher'] ?? 0) > 0) ? '#fff' : '#64748b' }};">
                    <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
                </div>
                <div>
                    <span class="kpi-label">Waiting on publisher</span>
                    <div class="kpi-value">{{ $kpisUnavailable ? '—' : (int) ($stats['waiting_on_publisher'] ?? 0) }}</div>
                    @if($kpisUnavailable)<span class="small text-muted">Unavailable</span>@endif
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ route('advertiser.orders', ['status' => 'in_progress']) }}" class="kpi-tile">
                <div class="kpi-icon" style="background:#1a585e;color:#fff;"><i class="fa-solid fa-clock" aria-hidden="true"></i></div>
                <div>
                    <span class="kpi-label">In progress</span>
                    <div class="kpi-value">{{ $kpisUnavailable ? '—' : $stats['in_progress'] }}</div>
                    @if($kpisUnavailable)<span class="small text-muted">Unavailable</span>@endif
                </div>
            </a>
        </div>
        @if(! $kpisUnavailable && (int) ($stats['cancelled'] ?? 0) > 0)
            <div class="col-12">
                <p class="small text-muted mb-0 px-1">{{ (int) $stats['cancelled'] }} cancelled — not counted in Active.</p>
            </div>
        @endif
    </div>

    <div class="row g-4 mb-4">
        <!-- Next actions + recommended -->
        <div class="col-lg-4">
            <div class="dash-panel h-100">
                <h5 class="mb-3">Next actions</h5>
                <div class="d-flex flex-column gap-2 mb-3">
                    @if($numbersFailed)
                        <a href="{{ route('advertiser.dashboard') }}" class="next-action">
                            <div>
                                <div class="na-title">Try again</div>
                                <p class="na-desc">Refresh the dashboard to load your next steps</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    @elseif($needsAction > 0)
                        <a href="{{ route('advertiser.orders', ['status' => 'needs_action']) }}" class="next-action border-warning">
                            <div>
                                <div class="na-title">Orders need attention</div>
                                <p class="na-desc">{{ $needsAction }} {{ $needsAction === 1 ? 'order needs' : 'orders need' }} a revised article or live-URL review</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                        <a href="{{ $browseCatalogUrl }}" class="next-action next-action-quiet">
                            <div>
                                <div class="na-title">Browse catalog</div>
                                <p class="na-desc">Find more publishers when you are ready</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    @else
                        @if($awaitingPayment > 0)
                            <a href="{{ route('advertiser.orders', ['status' => 'awaiting_payment']) }}" class="next-action">
                                <div>
                                    <div class="na-title">Complete payment</div>
                                    <p class="na-desc">{{ $awaitingPayment }} awaiting payment</p>
                                </div>
                                <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                            </a>
                        @endif
                        @if($upcomingScheduledCount > 0)
                            <a href="{{ route('advertiser.scheduled-orders', ['tab' => 'upcoming']) }}" class="next-action" id="dashUpcomingScheduledAction">
                                <div>
                                    <div class="na-title">Upcoming scheduled</div>
                                    <p class="na-desc">{{ $upcomingScheduledCount }} {{ $upcomingScheduledCount === 1 ? 'publication' : 'publications' }} waiting — reschedule, publish now, or cancel</p>
                                </div>
                                <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                            </a>
                        @endif
                        <a href="{{ $browseCatalogUrl }}" class="next-action">
                            <div>
                                <div class="na-title">Browse catalog</div>
                                <p class="na-desc">
                                    @if($hasOrderableArticle)
                                        You have an approved article ready — pick a publisher and assign it in cart
                                    @else
                                        Find publishers and add placements to your cart
                                    @endif
                                </p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                        @if($hasOrderableArticle)
                            <a href="{{ route('advertiser.content-library', ['status' => 'approved', 'availability' => 'available']) }}" class="next-action" id="dashOrderableLibraryAction">
                                <div>
                                    <div class="na-title">Content Library</div>
                                    <p class="na-desc">Review approved articles ready to place</p>
                                </div>
                                <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                            </a>
                        @else
                            <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="next-action" id="dashUploadLibraryAction">
                                <div>
                                    <div class="na-title">Upload an article</div>
                                    <p class="na-desc">Approve content in your library before checkout</p>
                                </div>
                                <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                            </a>
                        @endif
                        <a href="{{ $guidedFlowUrl }}" class="next-action">
                            <div>
                                <div class="na-title">Guided placement</div>
                                <p class="na-desc">Optional walkthrough: market → publishers → content → pay</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('advertiser.orders') }}" class="next-action">
                            <div>
                                <div class="na-title">Review orders</div>
                                <p class="na-desc">{{ $stats['in_progress'] }} in progress right now</p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('advertiser.add-funds') }}" class="next-action">
                            <div>
                                <div class="na-title">Add funds</div>
                                <p class="na-desc">
                                    @if($walletUnavailable)
                                        Spendable unavailable — try again or open Add funds
                                    @elseif(!empty($budgetStatus['low_balance']))
                                        Spendable is below your alert — top up to keep checkout ready
                                    @else
                                        Spendable €{{ number_format((float) ($wallet['spendable'] ?? 0), 2) }}
                                    @endif
                                </p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('advertiser.analytics') }}" class="next-action">
                            <div>
                                <div class="na-title">Spending history</div>
                                <p class="na-desc">
                                    @if($spendUnavailable)
                                        Spend totals unavailable
                                    @else
                                        Net €{{ number_format((float) ($spendSummary['net'] ?? 0), 2) }}
                                        · in progress €{{ number_format((float) ($spendSummary['in_progress'] ?? 0), 2) }}
                                    @endif
                                </p>
                            </div>
                            <i class="fa fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
                @if($recommendedSites->isNotEmpty())
                    <h6 class="mb-2">Recommended</h6>
                    <div class="recommended-sites">
                        @foreach($recommendedSites as $site)
                            @include('advertiser.partials.dashboard-recommended-site', [
                                'site' => $site,
                                'urlVisibility' => $urlVisibility,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Recent orders + spend -->
        <div class="col-lg-8 dash-recent-col">
            <div class="dash-spend-strip mb-3">
                <div class="dw-item">
                    <span class="dw-label">Net spend</span>
                    <div class="dw-value">{{ $spendUnavailable ? '—' : '€'.number_format((float) ($spendSummary['net'] ?? 0), 2) }}</div>
                </div>
                <div class="dw-item">
                    <span class="dw-label">Spent</span>
                    <div class="dw-value">{{ $spendUnavailable ? '—' : '€'.number_format((float) ($spendSummary['spent'] ?? 0), 2) }}</div>
                </div>
                <div class="dw-item">
                    <span class="dw-label">In progress</span>
                    <div class="dw-value">{{ $spendUnavailable ? '—' : '€'.number_format((float) ($spendSummary['in_progress'] ?? 0), 2) }}</div>
                </div>
                <div class="dw-item d-flex align-items-center">
                    <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}" class="btn btn-sm btn-outline-primary">Full history</a>
                </div>
                @if($spendUnavailable && $spendChartUnavailable)
                    <p class="dash-spend-empty mb-0">Spend history unavailable.</p>
                @elseif($spendUnavailable)
                    <p class="dash-spend-empty mb-0">Spend totals unavailable.</p>
                @elseif($spendChartUnavailable)
                    <p class="dash-spend-empty mb-0">
                        Chart unavailable —
                        <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}">open Full history</a>.
                    </p>
                @elseif(empty($spendCandles['has_spend']))
                    <p class="dash-spend-empty mb-0">No completed spend yet — paid placements will show here.</p>
                @endif
                @if(! $spendChartUnavailable && !empty($spendCandles['has_spend']))
                    <div class="w-100">
                        <p class="dash-spend-chart-hint" id="dashSpendChartHint">Solid = completed · Dim = still in progress</p>
                        <div class="dash-spend-chart-wrap" id="dashSpendChartWrap">
                            <canvas id="dashSpendChart" aria-label="Spend over recent days"></canvas>
                        </div>
                        <p class="dash-spend-chart-fallback d-none mb-0" id="dashSpendChartFallback" role="status">
                            Chart unavailable —
                            <a href="{{ route('advertiser.analytics', ['view' => 'day']) }}">open Full history</a>.
                        </p>
                    </div>
                @endif
            </div>
            <div class="recent-orders-glass">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 recent-orders-title">Recent orders</h5>
                        <a href="{{ route('advertiser.orders') }}" class="small recent-orders-link">View all</a>
                    </div>
                    @if($recentUnavailable)
                        <x-ui.empty-state
                            icon="fa-receipt"
                            title="Orders unavailable"
                            message="We could not load recent orders. Try again shortly."
                            primary-label="Try again"
                            :primary-url="route('advertiser.dashboard')"
                        />
                    @elseif($recentOrders->isEmpty())
                        <x-ui.empty-state
                            icon="fa-receipt"
                            title="No orders yet"
                            message="When you buy placements from the catalog, they’ll show up here."
                            primary-label="Browse catalog"
                            :primary-url="route('advertiser.catalog')"
                            secondary-label="Content library"
                            :secondary-url="route('advertiser.content-library')"
                        />
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Status</th>
                                        <th>Next</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentOrders as $order)
                                        @php
                                            $firstItem = $order->items->first();
                                            $numericOrder = preg_replace('/\D+/', '', (string) ($order->order_number ?? '')) ?: (string) $order->id;
                                            $statusMeta = \App\Support\AdvertiserOrderStatus::meta($order);
                                            $statusLabel = $statusMeta['label'];
                                            $statusDotClass = ($statusMeta['stage'] ?? '') === 'url_delivered'
                                                ? 'review'
                                                : (($statusMeta['stage'] ?? '') === 'review' ? 'pending' : (string) $order->status);
                                            $orderFocusUrl = route('advertiser.orders', ['focus' => 'order', 'order' => $order->id]);
                                            $siteModel = $firstItem?->relationLoaded('site') ? $firstItem->site : null;
                                            $canSeeRecentUrl = $siteModel
                                                ? $urlVisibility->canSee(auth()->user(), $siteModel)
                                                : false;
                                            $recentDisplayHost = $siteModel
                                                ? $urlVisibility->hostFor(auth()->user(), $siteModel)
                                                : null;
                                        @endphp
                                        <tr class="recent-order-row">
                                            <td class="py-3">
                                                <a href="{{ $orderFocusUrl }}" class="recent-order-num text-decoration-none stretched-link">#{{ $numericOrder }}</a>
                                                <div class="recent-order-site">{{ $firstItem->site_name ?? '—' }}</div>
                                                @if($canSeeRecentUrl && $recentDisplayHost && $firstItem?->site_id)
                                                    <a href="{{ route('advertiser.catalog.visit', $firstItem->site_id) }}"
                                                       target="_blank" rel="noopener" class="recent-order-url">
                                                        {{ \Illuminate\Support\Str::limit($recentDisplayHost, 48) }}
                                                        <i class="fa fa-external-link fa-xs"></i>
                                                    </a>
                                                @elseif($recentDisplayHost)
                                                    <div class="recent-order-url">{{ \Illuminate\Support\Str::limit($recentDisplayHost, 48) }}</div>
                                                @endif
                                                @if(($order->items->count() ?? 0) > 1)
                                                    <div class="small text-muted mt-1">+{{ $order->items->count() - 1 }} more site{{ $order->items->count() - 1 === 1 ? '' : 's' }}</div>
                                                @endif
                                                <div class="small text-muted mt-1">{{ $order->created_at?->format('M j, Y') }}</div>
                                            </td>
                                            <td class="py-3">
                                                <span class="order-status {{ $statusDotClass }}">
                                                    <span class="order-status-dot" aria-hidden="true"></span>
                                                    {{ $statusLabel }}
                                                </span>
                                            </td>
                                            <td class="py-3">
                                                <div class="recent-order-next">{{ $statusMeta['next'] }}</div>
                                                @if(!empty($statusMeta['auto_approve_hint']))
                                                    <p class="recent-order-hint mb-0">{{ $statusMeta['auto_approve_hint'] }}</p>
                                                @endif
                                            </td>
                                            <td class="text-end py-3 fw-semibold" style="color:#1a585e;">
                                                €{{ number_format((float) $order->total_amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="help-secondary mx-1 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <strong>Need assistance?</strong>
                <span class="text-muted small ms-1">Client manager · Mon–Fri, 9AM–6PM UTC</span>
            </div>
            <a href="{{ $supportTelegramUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                <i class="fa fa-message me-1"></i> Start chat
            </a>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
@if(!($isNewAdvertiser ?? false) && !($spendChartUnavailable ?? false) && !empty($spendCandles['has_spend']))
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('dashSpendChart');
    const wrap = document.getElementById('dashSpendChartWrap');
    const hint = document.getElementById('dashSpendChartHint');
    const fallback = document.getElementById('dashSpendChartFallback');

    function showChartFallback() {
        if (wrap) wrap.classList.add('d-none');
        if (hint) hint.classList.add('d-none');
        if (fallback) fallback.classList.remove('d-none');
    }

    if (!canvas || typeof Chart === 'undefined') {
        showChartFallback();
        return;
    }

    const rows = @json($spendCandles['series'] ?? []);

    function money(n) {
        const v = Number(n || 0);
        return v % 1 === 0 ? ('€' + v.toFixed(0)) : ('€' + v.toFixed(2));
    }

    try {
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.short_label || r.label),
                datasets: [
                    {
                        label: 'Spent (completed)',
                        data: rows.map(r => Number(r.spent || 0)),
                        backgroundColor: 'rgba(26, 88, 94, 0.88)',
                        hoverBackgroundColor: 'rgba(26, 88, 94, 1)',
                        stack: 'spend',
                        maxBarThickness: 28,
                        borderRadius: 4,
                    },
                    {
                        label: 'In progress',
                        data: rows.map(r => Number(r.in_progress || 0)),
                        backgroundColor: 'rgba(26, 88, 94, 0.28)',
                        hoverBackgroundColor: 'rgba(63, 174, 178, 0.55)',
                        borderColor: 'rgba(26, 88, 94, 0.35)',
                        borderWidth: 1,
                        stack: 'spend',
                        maxBarThickness: 28,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                const row = rows[items[0]?.dataIndex];
                                return row?.label || '';
                            },
                            label: (item) => {
                                const row = rows[item.dataIndex];
                                if (item.datasetIndex === 0) {
                                    return money(item.raw) + ' spent'
                                        + ' (' + (row?.spent_orders || 0) + ' completed)';
                                }
                                if (!item.raw) return null;
                                return money(item.raw) + ' in progress — adds to spent when completed'
                                    + ' (' + (row?.in_progress_orders || 0) + ' orders)';
                            },
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { callback: (v) => '€' + v },
                    },
                },
            },
        });
    } catch (err) {
        showChartFallback();
    }
});
</script>
@endif
@endpush
