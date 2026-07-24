<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Marketing') — SEOLinkBuildings</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.favicon')

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('assets/css/type-system.css') }}?v={{ @filemtime(public_path('assets/css/type-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/brand-colors.css') }}?v={{ @filemtime(public_path('assets/css/brand-colors.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/spacing-system.css') }}?v={{ @filemtime(public_path('assets/css/spacing-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/button-system.css') }}?v={{ @filemtime(public_path('assets/css/button-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/form-system.css') }}?v={{ @filemtime(public_path('assets/css/form-system.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/app-shell.css') }}?v={{ @filemtime(public_path('assets/css/app-shell.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/interaction.css') }}?v={{ @filemtime(public_path('assets/css/interaction.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/glass-tip.css') }}?v={{ @filemtime(public_path('assets/css/glass-tip.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}" rel="stylesheet">
    <link href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '5' }}" rel="stylesheet">
    <script src="{{ asset('assets/js/pulse-badge.js') }}?v={{ @filemtime(public_path('assets/js/pulse-badge.js')) ?: '1' }}"></script>
    <script src="{{ asset('assets/js/glass-tip.js') }}?v={{ @filemtime(public_path('assets/js/glass-tip.js')) ?: '1' }}" defer></script>

    <style>
        body, html { min-height: 100%; margin: 0; background: linear-gradient(180deg, #f3faf9 0%, #f8f9fa 40%); font-family: 'Poppins', system-ui, sans-serif; }
        #sidebar, #content, .top-navbar, footer, #toggleSidebar span.arrow { transition: all 0.3s ease-in-out; }
        #sidebar {
            min-width: 230px; max-width: 230px; background: #fff;
            border-right: 1px solid #e2e8f0; height: 100vh; position: fixed; top: 0; left: 0;
            display: flex; flex-direction: column; z-index: var(--shell-z-sidebar, 1050);
        }
        #sidebar .menu { flex-grow: 1; overflow-y: auto; padding-bottom: 16px; }
        #sidebar a {
            display: flex; align-items: center; gap: 10px; margin: 0 8px 2px; padding: 10px 12px;
            color: #475569; text-decoration: none; font-weight: 500; font-size: 0.9rem;
            border-radius: 8px; border: 1px solid transparent;
        }
        #sidebar a.active, #sidebar a:hover {
            background-color: var(--brand-primary-bg, #e6f5f5);
            color: var(--brand-primary, #185054);
            border-color: var(--brand-primary-border, #b8e4e4);
        }
        #sidebar a.active i, #sidebar a:hover i { color: var(--brand-primary, #185054); }
        #sidebar.collapsed { width: 70px; min-width: 70px; }
        #sidebar.collapsed a { justify-content: center; font-size: 0; }
        #sidebar.collapsed a i { font-size: 18px; }
        .mkt-nav-section {
            padding: 14px 20px 4px; font-size: 11px; font-weight: 600;
            letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8;
        }
        #sidebar.collapsed .mkt-nav-section { display: none; }
        .mkt-mode-badge {
            font-size: 12px; font-weight: 700; color: #185054;
            background: #e6f5f5; border: 1px solid #b8e4e4;
            border-radius: 999px; padding: 0.2rem 0.65rem;
        }
        .top-navbar {
            height: 70px; position: sticky; top: 0; left: 230px; right: 0;
            background: rgba(255,255,255,0.92); backdrop-filter: blur(8px);
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center; padding: 0 30px;
            z-index: var(--shell-z-topbar, 1060);
        }
        .top-navbar.collapsed { left: 70px; }
        #content { margin-left: 230px; padding: 20px 30px 30px; min-height: calc(100vh - 120px); }
        #content.collapsed { margin-left: 70px; }
        footer { margin-left: 230px; padding: 15px; text-align: center; background: #fff; border-top: 1px solid #e2e8f0; }
        footer.collapsed { margin-left: 70px; }
        #toggleSidebar span.arrow { display: inline-block; font-size: 18px; }
        #toggleSidebar.collapsed span.arrow { transform: rotate(180deg); }
        .topbar-icon-btn {
            width: 36px; height: 36px; border-radius: 8px; display: inline-flex;
            align-items: center; justify-content: center; padding: 0; color: #495057;
            border: 1px solid #dee2e6; background: #fff;
        }
        .topbar-icon-btn:hover { background: #f8f9fa; color: #185054; border-color: #b8e4e4; }
        @media (max-width: 768px) {
            #sidebar { top: 70px; height: calc(100vh - 70px); left: -230px; }
            #sidebar.show { left: 0; }
            #content, .top-navbar, footer { margin-left: 0 !important; }
            .top-navbar { left: 0 !important; padding-left: 10px; padding-right: 10px; }
        }
    </style>
    @stack('head')
</head>
<body class="role-shell-marketing">

<div id="sidebar">
    <div class="menu">
        <div class="text-center my-3">
            <img id="logoSidebar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="64" style="width:auto;max-width:min(320px,100%);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </div>

        <div class="mkt-nav-section">Marketing</div>
        <a href="{{ route('marketing.dashboard') }}" class="{{ request()->routeIs('marketing.dashboard') ? 'active' : '' }}">
            <i class="fa fa-tachometer-alt"></i> <span>Dashboard</span>
        </a>
        <a href="{{ route('marketing.history') }}" class="{{ request()->routeIs('marketing.history') ? 'active' : '' }}">
            <i class="fa fa-history"></i> <span>My task history</span>
        </a>

        <div class="mkt-nav-section">Catalog ops</div>
        <a href="{{ route('marketing.sites.index') }}" class="{{ request()->routeIs('marketing.sites.*') ? 'active' : '' }}">
            <i class="fa fa-globe"></i> <span>Sites</span>
        </a>
        <a href="{{ route('marketing.bulk-site-requests.index') }}" class="{{ request()->routeIs('marketing.bulk-site-requests.*') ? 'active' : '' }}">
            <i class="fa fa-layer-group"></i> <span>Bulk requests</span>
        </a>
    </div>
</div>

<div class="top-navbar">
    <div class="mobile-left d-flex align-items-center gap-2">
        <button id="toggleSidebar" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Toggle sidebar navigation" title="Toggle sidebar">
            <span class="arrow" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        </button>
        <a href="/" class="d-flex align-items-center">
            <img id="logoNavbar" src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}" height="68" style="width:auto;max-width:min(360px,80vw);object-fit:contain;background:transparent" alt="SEOLinkBuildings">
        </a>
        <div class="d-none d-md-block">
            <span class="mkt-mode-badge">Marketing workspace</span>
            <span class="ms-2">
                @include('partials.role-switcher', ['variant' => 'outline-secondary'])
            </span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        @include('partials.notification-center')
        <div class="dropdown">
            <button class="btn dropdown-toggle d-flex align-items-center gap-1"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                    aria-label="Account menu">
                @php $user = auth()->user(); @endphp
                @if($user->avatar)
                    <img src="{{ $user->avatar }}" alt="" class="rounded-circle" style="width: 36px; height: 36px; object-fit: cover;">
                @else
                    <div class="rounded-circle text-white d-flex justify-content-center align-items-center"
                         style="width: 36px; height: 36px; font-weight: 600; background: #5bc4c7;"
                         aria-hidden="true">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-2">
                    <strong>{{ $user->name }}</strong><br>
                    <small class="text-muted">{{ $user->email }}</small>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="{{ route('profile') }}">
                        <i class="fa fa-user" aria-hidden="true"></i> Profile
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item text-danger" type="submit">
                            <i class="fa fa-sign-out-alt" aria-hidden="true"></i> Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div id="content">
    @yield('content')
</div>

<footer>
    © {{ date('Y') }} SEOLinkBuildings · Marketing
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/js/modal-stack.js') }}?v={{ @filemtime(public_path('assets/js/modal-stack.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/slb-confirm.js') }}?v={{ @filemtime(public_path('js/slb-confirm.js')) ?: '1' }}"></script>
<script>
    const toggleBtn = document.getElementById('toggleSidebar');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const topNavbar = document.querySelector('.top-navbar');
    const footerEl = document.querySelector('footer');

    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
        content.classList.add('collapsed');
        topNavbar.classList.add('collapsed');
        footerEl.classList.add('collapsed');
        toggleBtn.classList.add('collapsed');
    }

    toggleBtn.addEventListener('click', function () {
        if (window.innerWidth > 768) {
            sidebar.classList.toggle('collapsed');
            content.classList.toggle('collapsed');
            topNavbar.classList.toggle('collapsed');
            footerEl.classList.toggle('collapsed');
            toggleBtn.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        } else {
            sidebar.classList.toggle('show');
        }
    });

    document.body.classList.remove('layout-dark');
    try { localStorage.removeItem('layoutDarkMode'); } catch (e) {}
</script>
<script src="{{ asset('js/role-switch.js') }}?v={{ @filemtime(public_path('js/role-switch.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/notification-center.js') }}?v={{ @filemtime(public_path('assets/js/notification-center.js')) ?: '5' }}" defer></script>
@stack('scripts')
</body>
</html>
