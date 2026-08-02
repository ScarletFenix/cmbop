@extends('publisher.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="mb-3 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <a href="{{ route('publisher.websites') }}" class="small text-muted text-decoration-none">← Websites</a>
            <h3 class="mt-2 mb-1">Complete website details</h3>
            <p class="text-muted small mb-0">
                Metrics, geo, and niches were added by our team. Finish description, link type, and timing for each site.
                Then open <strong>Review &amp; submit</strong> for a final check before admin review.
            </p>
        </div>
        @if(($detailsCompleteCount ?? 0) > 0)
            <a href="{{ route('publisher.bulk-sites.review') }}" class="btn btn-primary align-self-center">
                <i class="fa fa-clipboard-check me-1"></i> Review &amp; submit ({{ $detailsCompleteCount }})
            </a>
        @endif
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(($awaitingCount ?? 0) > 0 && ($detailsCompleteCount ?? 0) > 0)
        <div class="alert alert-light border small mb-3">
            {{ $awaitingCount }} still need details · {{ $detailsCompleteCount }} ready for your final review.
        </div>
    @endif

    @forelse($sites as $site)
        @php
            $isComplete = $site->hasDetailsComplete();
            $open = (int) session('complete_site_id') === (int) $site->id || $errors->any() && (int) old('_site_id') === (int) $site->id;
            $siteNiches = collect($site->categories ?? [])
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
                ->values()
                ->all();
        @endphp
        <div class="card border-0 shadow-sm mb-3" id="site-{{ $site->id }}">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <h5 class="mb-0">{{ $site->site_name }}</h5>
                        <div class="small text-muted">
                            {{ $site->site_url }} · €{{ number_format((float) $site->price, 2) }}
                            · DR {{ $site->dr }} / DA {{ $site->da }}
                            · {{ strtoupper($site->language) }}/{{ strtoupper($site->country) }}
                        </div>
                    </div>
                    @if($isComplete)
                        <span class="badge text-bg-success-subtle text-success border align-self-start">Saved — ready to review</span>
                    @else
                        <span class="badge text-bg-light border align-self-start">Needs your details</span>
                    @endif
                </div>

                <div class="mb-3">
                    <div class="text-muted small mb-1">Niches (set by our team)</div>
                    @if($siteNiches !== [])
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($siteNiches as $niche)
                                <span class="badge text-bg-light border">{{ $niche }}</span>
                            @endforeach
                        </div>
                    @else
                        <div class="small text-danger">Niches missing — contact support before submitting.</div>
                    @endif
                </div>

                <form method="POST" action="{{ route('publisher.bulk-sites.complete.store', $site->id) }}" class="row g-3">
                    @csrf
                    <input type="hidden" name="_site_id" value="{{ $site->id }}">

                    <div class="col-md-6">
                        <label class="form-label">Example article URL *</label>
                        <input type="url" name="exampleUrl" class="form-control" required
                               value="{{ old('exampleUrl', $site->example_url) }}" placeholder="https://…/sample-post">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Turnaround *</label>
                        <select name="turnaround_time" class="form-select" required>
                            @foreach(['24h'=>'24 Hours','48h'=>'48 Hours','3days'=>'3 Days','5days'=>'5 Days','7days'=>'7 Days'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('turnaround_time', $site->turnaround_time) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Publication *</label>
                        <select name="publicationTime" class="form-select" required>
                            @foreach(['6months'=>'6 Months','1year'=>'1 Year','permanent'=>'Permanent'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('publicationTime', $site->publication_time) === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Link type *</label>
                        <select name="link_type" class="form-select" required>
                            <option value="dofollow" @selected(old('link_type', $site->link_type) === 'dofollow')>DoFollow</option>
                            <option value="nofollow" @selected(old('link_type', $site->link_type) === 'nofollow')>NoFollow</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tag</label>
                        @php
                            $defaultTag = 'as_you_prefer';
                            if ($site->sponsored) {
                                $defaultTag = 'sponsored';
                            } elseif ($site->partner_material) {
                                $defaultTag = 'partner_material';
                            } elseif ($site->as_you_prefer) {
                                $defaultTag = 'as_you_prefer';
                            }
                        @endphp
                        <select name="site_tag" class="form-select">
                            <option value="as_you_prefer" @selected(old('site_tag', $defaultTag) === 'as_you_prefer')>As you prefer</option>
                            <option value="sponsored" @selected(old('site_tag', $defaultTag) === 'sponsored')>Sponsored</option>
                            <option value="partner_material" @selected(old('site_tag', $defaultTag) === 'partner_material')>Partner material</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description * (min 50 characters)</label>
                        <textarea name="siteDescription" class="form-control" rows="4" minlength="50" required>{{ old('siteDescription', str_starts_with((string) $site->description, 'Please replace') ? '' : $site->description) }}</textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" @disabled($siteNiches === [])>
                            {{ $isComplete ? 'Update saved details' : 'Save for review' }}
                        </button>
                        @if($isComplete)
                            <a href="{{ route('publisher.bulk-sites.review') }}" class="btn btn-outline-primary">Go to Review &amp; submit</a>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <h5>Nothing to complete</h5>
                <p class="text-muted mb-3">When our team seeds your bulk sites, they’ll appear here.</p>
                <a href="{{ route('publisher.websites') }}" class="btn btn-outline-primary">Back to websites</a>
            </div>
        </div>
    @endforelse
</div>
@endsection
