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
                @php
                    $subjectUrl = marketing_history_subject_url($log);
                    $bulkUrl = marketing_history_bulk_url($log);
                @endphp
                <tr>
                    <td class="small text-nowrap">
                        <div>{{ $log->created_at?->diffForHumans() }}</div>
                        <span class="text-muted">{{ $log->created_at?->format('d M Y H:i') }}</span>
                    </td>
                    <td>
                        <div class="fw-semibold">{{ marketing_task_label($log->action) }}</div>
                    </td>
                    <td class="small">
                        @if($subjectUrl)
                            <a href="{{ $subjectUrl }}">{{ $log->subject_label ?: 'Open' }}</a>
                        @else
                            {{ $log->subject_label ?: '—' }}
                        @endif
                        @if($bulkUrl)
                            <div>
                                <a href="{{ $bulkUrl }}">Bulk request</a>
                            </div>
                        @endif
                    </td>
                    <td class="small">{{ $log->description }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-4">
                        @if(!empty($filtersActive))
                            <div class="mb-2">No tasks match these filters.</div>
                            <a href="{{ route('marketing.history') }}" class="btn btn-sm btn-outline-secondary">Reset filters</a>
                        @else
                            <div class="mb-2">No marketing tasks recorded yet. Seed sites or edit listings to build your history.</div>
                            <a href="{{ route('marketing.sites.create') }}" class="btn btn-sm btn-outline-primary">Add site for publisher</a>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
