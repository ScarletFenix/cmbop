<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>When</th>
                <th>Task</th>
                <th>Subject</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="small text-nowrap">
                        {{ $log->created_at?->format('d M Y') }}<br>
                        <span class="text-muted">{{ $log->created_at?->format('H:i') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ marketing_task_label($log->action) }}</div>
                    </td>
                    <td class="small">{{ $log->subject_label ?: '—' }}</td>
                    <td class="small">{{ $log->description }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        No marketing tasks recorded yet. Seed sites or edit listings to build your history.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
