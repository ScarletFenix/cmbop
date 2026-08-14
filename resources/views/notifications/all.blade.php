@extends($layout)

@section('title', 'Notifications')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/pulse-badge.css') }}?v={{ @filemtime(public_path('assets/css/pulse-badge.css')) ?: '1' }}">
<link rel="stylesheet" href="{{ asset('assets/css/notification-center.css') }}?v={{ @filemtime(public_path('assets/css/notification-center.css')) ?: '4' }}">

<div class="container-fluid py-2 nc-theme nc-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 nc-page-header">
        <div>
            <h2>All notifications</h2>
            <p>
                @if($unreadCount > 0)
                    <span class="pulse-badge is-pulsing d-inline-flex align-items-center justify-content-center rounded-pill text-white me-1"
                          style="min-width:18px;height:18px;padding:0 5px;font-size:10px;font-weight:700;background:#dc3545;">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    unread
                @else
                    All caught up
                @endif
                @if($notifications->total())
                    <span class="text-muted"> · {{ $notifications->total() }} total</span>
                @endif
            </p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all', absolute: false) }}" class="m-0" id="markAllReadForm">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark all read</button>
        </form>
    </div>

    <form method="GET" action="{{ route('notifications.all', absolute: false) }}" class="row g-2 align-items-end mb-3 nc-filter-bar">
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Search</label>
            <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Search notifications…" title="Results update as you type" autocomplete="off" enterkeyhint="search" data-slb-live-search="form">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
                @foreach(['all' => 'All', 'unread' => 'Unread', 'archived' => 'Archived', 'orders' => 'Orders', 'messages' => 'Messages', 'payments' => 'Payments', 'account' => 'Account', 'system' => 'System', 'support' => 'Support'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['category'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-nc w-100">Filter</button>
        </div>
    </form>

    <div class="nc-page-list">
        @forelse($notifications as $notification)
            @include('partials.notification-card', [
                'notification' => $notification,
                'as' => 'a',
                'showTools' => false,
                'onclick' => 'markReadThenGo(event, ' . $notification->id . ', ' . json_encode($notification->actionUrlFor(auth()->user()) ?: '') . ')',
            ])
        @empty
            <div class="nc-empty">
                @if(($filters['category'] ?? '') === 'unread')
                    You're all caught up. Switch to All to see earlier notifications.
                @elseif(($filters['category'] ?? '') === 'archived')
                    No archived notifications.
                @else
                    You're all caught up. New activity will show up here.
                @endif
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $notifications->withQueryString()->links() }}
    </div>
</div>

<script>
document.getElementById('markAllReadForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    var unread = {{ (int) $unreadCount }};
    var msg = unread > 0
        ? 'Mark all ' + unread + ' notifications as read?'
        : 'Mark all notifications as read?';
    if (!window.confirm(msg)) return;
    fetch(this.action, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    }).then(function () { window.location.reload(); });
});

function markReadThenGo(e, id, url) {
    if (!url) {
        e.preventDefault();
        return;
    }
    e.preventDefault();
    fetch('/notifications/' + id + '/read', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    }).finally(function () {
        window.location.href = url;
    });
}
</script>
@endsection
