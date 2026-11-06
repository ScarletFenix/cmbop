@extends('admin.layouts.app')

@section('title', 'Create managed order')

@section('content')
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Create order · Managed by SLB',
        'subtitle' => 'Charge the advertiser wallet once and open a managed desk row. The advertiser does not need to cart or confirm.',
        'actionUrl' => route('admin.managed-orders.index'),
        'actionLabel' => 'All managed',
        'actionIcon' => 'fa-arrow-left',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Order details</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.managed-orders.confirm') }}" class="vstack gap-3">
                        @csrf
                        <div>
                            <label class="form-label" for="advertiser_id">Advertiser</label>
                            <select name="advertiser_id" id="advertiser_id" class="form-select" required>
                                <option value="">Select advertiser…</option>
                                @foreach($advertisers as $user)
                                    <option value="{{ $user->id }}" @selected((int) old('advertiser_id') === (int) $user->id)>
                                        {{ $user->name }} · {{ $user->email }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Search below if the list is long, then reload options.</div>
                        </div>
                        <div>
                            <label class="form-label" for="site_id">Site</label>
                            <select name="site_id" id="site_id" class="form-select" required>
                                <option value="">Select site…</option>
                                @foreach($sites as $site)
                                    <option value="{{ $site->id }}" @selected((int) old('site_id') === (int) $site->id)>
                                        {{ $site->site_name }} · {{ $site->domain ?: $site->site_url }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="anchor_text">Anchor text</label>
                            <input type="text" name="anchor_text" id="anchor_text" class="form-control" maxlength="500" value="{{ old('anchor_text') }}" required>
                        </div>
                        <div>
                            <label class="form-label" for="target_url">Target URL</label>
                            <input type="url" name="target_url" id="target_url" class="form-control" maxlength="2000" value="{{ old('target_url') }}" placeholder="https://…" required>
                        </div>
                        <div>
                            <label class="form-label" for="content_source">Content source</label>
                            <select name="content_source" id="content_source" class="form-select" required>
                                <option value="buyer" @selected(old('content_source', 'buyer') === 'buyer')>Advertiser-provided article URL</option>
                                <option value="slb" @selected(old('content_source') === 'slb')>SLB writing</option>
                            </select>
                        </div>
                        <div id="contentUrlWrap">
                            <label class="form-label" for="content_url">Content URL</label>
                            <input type="url" name="content_url" id="content_url" class="form-control" maxlength="2000" value="{{ old('content_url') }}" placeholder="https://…">
                            <div class="form-text">Required when the advertiser supplies the article.</div>
                        </div>
                        <div>
                            <label class="form-label" for="managed_assignee_id">Assignee (optional)</label>
                            <select name="managed_assignee_id" id="managed_assignee_id" class="form-select">
                                <option value="">Unassigned</option>
                                @foreach($assignees as $staff)
                                    <option value="{{ $staff->id }}" @selected((int) old('managed_assignee_id') === (int) $staff->id)>{{ $staff->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="managed_notes">Internal notes (optional)</label>
                            <textarea name="managed_notes" id="managed_notes" class="form-control" rows="3" maxlength="5000">{{ old('managed_notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary align-self-start">Review charge</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-0"><strong>Find advertiser</strong></div>
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.managed-orders.create') }}" class="d-flex gap-2">
                        <input type="hidden" name="site_q" value="{{ $siteSearch }}">
                        <input type="search" name="advertiser_q" class="form-control" value="{{ $advertiserSearch }}" placeholder="Name, email, or id">
                        <button type="submit" class="btn btn-outline-primary">Search</button>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Find site</strong></div>
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.managed-orders.create') }}" class="d-flex gap-2">
                        <input type="hidden" name="advertiser_q" value="{{ $advertiserSearch }}">
                        <input type="search" name="site_q" class="form-control" value="{{ $siteSearch }}" placeholder="Name, domain, or id">
                        <button type="submit" class="btn btn-outline-primary">Search</button>
                    </form>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0">
                Price uses the same advertiser-facing listing price as normal checkout. Payment is wallet only.
                The catalog publisher will not see this order in Tasks and will not be paid through the platform.
            </p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const source = document.getElementById('content_source');
    const wrap = document.getElementById('contentUrlWrap');
    const url = document.getElementById('content_url');
    function sync() {
        const buyer = source && source.value === 'buyer';
        if (wrap) wrap.style.display = buyer ? '' : 'none';
        if (url) url.required = !!buyer;
    }
    if (source) {
        source.addEventListener('change', sync);
        sync();
    }
})();
</script>
@endpush
