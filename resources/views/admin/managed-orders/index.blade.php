@extends('admin.layouts.app')

@section('title', 'Managed by SLB')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Managed by SLB',
        'subtitle' => 'Email-managed placements tracked by the team. Publishers do not see these in Tasks and are not paid through the portal.',
        'actionUrl' => route('admin.managed-orders.create'),
        'actionLabel' => 'Create order',
        'actionIcon' => 'fa-plus',
    ])

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="GET" action="{{ route('admin.managed-orders.index') }}" class="d-flex flex-wrap gap-2 mb-3">
        <select name="status" class="form-select" style="max-width:16rem;">
            <option value="">All internal statuses</option>
            @foreach($statuses as $st)
                <option value="{{ $st }}" @selected($status === $st)>{{ str_replace('_', ' ', $st) }}</option>
            @endforeach
        </select>
        <select name="assignee_id" class="form-select" style="max-width:16rem;">
            <option value="">All assignees</option>
            @foreach($assignees as $user)
                <option value="{{ $user->id }}" @selected((int) $assigneeId === (int) $user->id)>{{ $user->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Advertiser</th>
                        <th>Site</th>
                        <th>Amount</th>
                        <th>Assignee</th>
                        <th>Internal status</th>
                        <th>Live URL</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        <tr>
                            <td>
                                <a href="{{ route('admin.orders.show', $item->order_id) }}" class="link-dark">#{{ $item->order?->order_number ?: $item->order_id }}</a>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $item->order?->user?->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $item->order?->user?->email }}</div>
                            </td>
                            <td>{{ $item->site_name ?: ($item->site?->site_name ?? '—') }}</td>
                            <td>€{{ number_format((float) $item->price, 2) }}</td>
                            <td>{{ $item->managedAssignee?->name ?? 'Unassigned' }}</td>
                            <td><span class="badge text-bg-light text-dark border">{{ str_replace('_', ' ', (string) ($item->managed_internal_status ?: '—')) }}</span></td>
                            <td class="small">
                                @if(filled($item->live_url))
                                    <a href="{{ $item->live_url }}" target="_blank" rel="noopener">Open</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small text-muted">{{ optional($item->updated_at)->format('Y-m-d H:i') }}</td>
                            <td><a href="{{ route('admin.managed-orders.show', $item) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-muted p-3">No managed placements yet. Mark a paid order from the order page.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($items, 'links'))
            <div class="p-3">{{ $items->links() }}</div>
        @endif
    </div>
</div>
@endsection
