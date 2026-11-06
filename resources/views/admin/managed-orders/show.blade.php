@extends('admin.layouts.app')

@section('title', 'Managed · #'.($order->order_number ?: $order->id))

@section('content')
@php
    $statusLabels = [
        'awaiting_content' => 'Awaiting content',
        'with_publisher' => 'With publisher',
        'live_url_received' => 'Live URL received',
        'completed' => 'Completed',
    ];
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Managed by SLB · #'.($order->order_number ?: $order->id),
        'subtitle' => 'Track email fulfilment, attach the live URL, and complete without a publisher wallet payout.',
        'actionUrl' => route('admin.managed-orders.index'),
        'actionLabel' => 'All managed',
        'actionIcon' => 'fa-arrow-left',
    ])

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge text-bg-light text-dark border">Order {{ $order->status }}</span>
        <span class="badge text-bg-light text-dark border">Payment {{ $order->payment_status }}</span>
        <span class="badge text-bg-light text-dark border">€{{ number_format((float) $item->price, 2) }}</span>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.show', $order) }}">Full order</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0"><strong>Placement</strong></div>
                <div class="card-body">
                    <div class="mb-2"><span class="text-muted small">Advertiser</span><div class="fw-semibold">{{ $order->user?->name }}</div><div class="small text-muted">{{ $order->user?->email }}</div></div>
                    <div class="mb-2"><span class="text-muted small">Site</span><div class="fw-semibold">{{ $item->site_name }}</div><div class="small text-muted">{{ $item->site_url }}</div></div>
                    <div class="mb-2"><span class="text-muted small">Catalog publisher (not notified)</span><div>{{ $item->site?->publisher?->name ?? '—' }}</div></div>
                    <div class="mb-2"><span class="text-muted small">Content</span>
                        @if(filled($item->content_link))
                            <div class="small"><a href="{{ $item->content_link }}" target="_blank" rel="noopener">Open content link</a></div>
                        @else
                            <div class="small text-muted">No content link</div>
                        @endif
                    </div>
                    <div class="mb-2"><span class="text-muted small">External reference</span>
                        <div class="text-break">{{ filled($item->managed_external_reference) ? $item->managed_external_reference : '—' }}</div>
                    </div>
                    <div><span class="text-muted small">Marked</span><div>{{ optional($item->managed_marked_at)->format('Y-m-d H:i') ?: '—' }}</div></div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0"><strong>Desk</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.managed-orders.update', $item) }}" class="vstack gap-3">
                        @csrf
                        <div>
                            <label class="form-label" for="managed_assignee_id">Assignee</label>
                            <select name="managed_assignee_id" id="managed_assignee_id" class="form-select">
                                <option value="">Unassigned</option>
                                @foreach($assignees as $user)
                                    <option value="{{ $user->id }}" @selected((int) $item->managed_assignee_id === (int) $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="managed_internal_status">Internal status</label>
                            <select name="managed_internal_status" id="managed_internal_status" class="form-select">
                                @foreach($statuses as $st)
                                    <option value="{{ $st }}" @selected($item->managed_internal_status === $st)>{{ $statusLabels[$st] ?? $st }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="managed_external_reference">External reference</label>
                            <input type="text" name="managed_external_reference" id="managed_external_reference" class="form-control" maxlength="500"
                                   value="{{ old('managed_external_reference', $item->managed_external_reference) }}"
                                   placeholder="Email subject or thread link">
                        </div>
                        <div>
                            <label class="form-label" for="managed_notes">Notes</label>
                            <textarea name="managed_notes" id="managed_notes" class="form-control" rows="4" maxlength="5000">{{ old('managed_notes', $item->managed_notes) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-primary align-self-start">Save desk</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0"><strong>Live URL</strong></div>
                <div class="card-body">
                    @if(filled($item->live_url))
                        <p class="mb-3"><a href="{{ $item->live_url }}" target="_blank" rel="noopener">{{ $item->live_url }}</a></p>
                    @endif
                    @if($order->status !== 'completed')
                        <form method="POST" action="{{ route('admin.managed-orders.live-url', $item) }}" class="vstack gap-2">
                            @csrf
                            <label class="form-label" for="live_url">Attach or update live URL</label>
                            <input type="url" name="live_url" id="live_url" class="form-control" value="{{ old('live_url', $item->live_url) }}" placeholder="https://…" required>
                            <button type="submit" class="btn btn-primary align-self-start">Save live URL</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0"><strong>Complete</strong></div>
                <div class="card-body">
                    @if($order->status === 'completed')
                        <p class="mb-0 text-muted">Completed{{ $item->managedCompletedBy ? ' by '.$item->managedCompletedBy->name : '' }}. Publisher wallet was not credited for this managed placement.</p>
                    @else
                        <p class="small text-muted">Completing notifies the advertiser and closes the order. It does <strong>not</strong> credit the catalog publisher wallet.</p>
                        <form method="POST" action="{{ route('admin.managed-orders.complete', $item) }}" onsubmit="return confirm('Complete this managed order without paying the publisher through the platform?');">
                            @csrf
                            @if(!filled($item->live_url))
                                <div class="mb-2">
                                    <label class="form-label" for="complete_live_url">Live URL</label>
                                    <input type="url" name="live_url" id="complete_live_url" class="form-control" required placeholder="https://…">
                                </div>
                            @endif
                            <button type="submit" class="btn btn-success">Complete · Managed by SLB</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong>Order chat (SLB team)</strong>
                    <span class="small text-muted">Advertiser messages route here — never to the publisher.</span>
                </div>
                <div class="card-body">
                    <div id="managedChatThread" class="border rounded p-3 mb-3" style="max-height: 280px; overflow:auto; background:#f8fafb;"></div>
                    @if($order->payment_status === 'paid' && $order->status !== 'cancelled')
                        <form id="managedChatForm" class="d-flex gap-2">
                            <input type="text" id="managedChatInput" class="form-control" maxlength="5000" placeholder="Reply as SEOLinkBuildings team…">
                            <button type="submit" class="btn btn-primary">Send</button>
                        </form>
                    @else
                        <p class="text-muted mb-0">Chat is closed for this order.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const orderId = {{ (int) $order->id }};
    const thread = document.getElementById('managedChatThread');
    const form = document.getElementById('managedChatForm');
    const input = document.getElementById('managedChatInput');
    if (!thread) return;

    function renderMessages(messages) {
        if (!messages || !messages.length) {
            thread.innerHTML = '<div class="text-muted small">No messages yet.</div>';
            return;
        }
        thread.innerHTML = messages.map(function (m) {
            const name = (m.user && m.user.name) ? m.user.name : (m.user_name || m.sender_type);
            const who = m.sender_type === 'admin' ? (name || 'SLB team') : name;
            const label = m.sender_type === 'admin' ? 'SLB' : (m.sender_type === 'advertiser' ? 'Advertiser' : m.sender_type);
            const text = (m.message || '').replace(/</g, '&lt;');
            return '<div class="mb-2"><span class="badge text-bg-light text-dark border me-1">' + label + '</span><strong>' + who + '</strong><div class="small">' + text + '</div></div>';
        }).join('');
        thread.scrollTop = thread.scrollHeight;
    }

    function loadChat() {
        fetch(@json(url('/chat/messages/'.$order->id)), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(r => r.json()).then(data => {
            if (data && data.success) renderMessages(data.messages || []);
        }).catch(function () {});
    }

    loadChat();
    setInterval(loadChat, 15000);

    if (form && input) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const body = (input.value || '').trim();
            if (!body) return;
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            fetch(@json(url('/chat/send/'.$order->id)), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token || ''
                },
                credentials: 'same-origin',
                body: JSON.stringify({ message: body })
            }).then(r => r.json()).then(data => {
                if (data && data.success) {
                    input.value = '';
                    loadChat();
                } else {
                    alert((data && data.message) || 'Could not send message.');
                }
            }).catch(function () {
                alert('Could not send message.');
            });
        });
    }
})();
</script>
@endpush
