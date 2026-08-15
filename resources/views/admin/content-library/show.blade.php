@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <a href="{{ route('admin.content-library.index') }}" class="small text-muted text-decoration-none">← Content Library</a>
            <h1 class="h3 mb-1 mt-1">{{ $submission->title ?: $submission->original_filename }}</h1>
            <p class="text-muted mb-0 small">
                #{{ $submission->id }} · {{ $submission->user?->email }} ·
                {{ strtoupper((string) $submission->country) }}/{{ strtoupper((string) $submission->language) }} ·
                {{ str_replace('_', ' ', (string) $submission->moderation_status) }}
            </p>
        </div>
        @if($submission->hasStoredFile())
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.content-library.download', $submission) }}">
                Download .docx
            </a>
        @endif
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Details</strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Advertiser</dt>
                        <dd class="col-7">{{ $submission->user?->name }}<br><span class="text-muted">{{ $submission->user?->email }}</span></dd>
                        <dt class="col-5 text-muted">Scores</dt>
                        <dd class="col-7">U {{ $submission->uniqueness_score ?? '—' }}% · Q {{ $submission->quality_score ?? '—' }}%</dd>
                        <dt class="col-5 text-muted">Words</dt>
                        <dd class="col-7">{{ $submission->word_count ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Order</dt>
                        <dd class="col-7">{{ $submission->order_id ? '#'.$submission->order_id : '—' }}</dd>
                        <dt class="col-5 text-muted">Expires</dt>
                        <dd class="col-7">{{ optional($submission->expires_at)->toDayDateTimeString() ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Archived</dt>
                        <dd class="col-7">{{ optional($submission->archived_at)->toDayDateTimeString() ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            @if($submission->moderationLog)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Moderation</strong></div>
                    <div class="card-body small">
                        <div class="mb-2">
                            Current scan
                            <a href="{{ route('admin.moderation.show', $submission->moderationLog) }}">#{{ $submission->moderationLog->id }}</a>
                            · {{ $submission->moderationLog->categoryLabel() }}
                            @if($submission->moderationLog->admin_override)
                                <span class="badge bg-warning text-dark">Override</span>
                            @endif
                        </div>
                        @if($submission->moderationLog->admin_notes)
                            <p class="mb-2">{{ $submission->moderationLog->admin_notes }}</p>
                        @endif
                        @if(! $submission->moderationLog->passed && $submission->moderationLog->status === 'rejected')
                            <form method="POST" action="{{ route('admin.moderation.override', $submission->moderationLog) }}"
                                  data-slb-confirm="Approve this submission via admin override?"
                                  data-slb-confirm-title="Override moderation?"
                                  data-slb-confirm-text="Approve override"
                                  data-slb-confirm-icon="warning">
                                @csrf
                                <textarea name="notes" class="form-control form-control-sm mb-2" rows="2" required minlength="3" maxlength="2000" placeholder="Why this article is allowed">{{ old_text('notes') }}</textarea>
                                <button class="btn btn-sm btn-primary" type="submit">Approve override</button>
                            </form>
                        @elseif($submission->moderationLog->admin_override)
                            <form method="POST" action="{{ route('admin.moderation.revert', $submission->moderationLog) }}"
                                  data-slb-confirm="Re-check this article and drop the override?"
                                  data-slb-confirm-title="Revert override?"
                                  data-slb-confirm-text="Revert"
                                  data-slb-confirm-icon="warning">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger" type="submit">Revert override</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            @if(($reasons['blocking'] ?? []) !== [] || ($reasons['advisory'] ?? []) !== [])
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Evaluation reasons</strong></div>
                    <div class="card-body small">
                        @if(($reasons['blocking'] ?? []) !== [])
                            <div class="fw-semibold text-danger mb-1">Blocking</div>
                            <ul class="mb-2">
                                @foreach($reasons['blocking'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if(($reasons['advisory'] ?? []) !== [])
                            <div class="fw-semibold text-warning mb-1">Advisory</div>
                            <ul class="mb-0">
                                @foreach($reasons['advisory'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Preview</strong></div>
                <div class="card-body">
                    @if($previewHtml)
                        <div class="border rounded-3 p-3 bg-white" style="max-height:70vh;overflow:auto;">
                            {!! $previewHtml !!}
                        </div>
                    @else
                        <p class="text-muted mb-0">No preview HTML stored for this article.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
