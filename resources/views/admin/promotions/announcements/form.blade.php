@extends('admin.layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <div class="mb-4">
        <a href="{{ route('admin.promotions.announcements.index') }}" class="text-decoration-none small text-muted">
            <i class="fa fa-arrow-left me-1"></i> Back to announcements
        </a>
        <h1 class="h3 mb-1 mt-2">{{ $mode === 'create' ? 'New Announcement' : 'Edit Announcement' }}</h1>
        <p class="text-muted mb-0">Publish discounts, Black Friday offers, or platform change notices.</p>
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

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST"
                  action="{{ $mode === 'create' ? route('admin.promotions.announcements.store') : route('admin.promotions.announcements.update', $announcement) }}">
                @csrf
                @if($mode === 'edit')
                    @method('PUT')
                @endif

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" value="{{ old('title', $announcement->title) }}" required maxlength="160" placeholder="Black Friday — 25% off guest posts">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Message</label>
                        <textarea name="message" class="form-control" rows="4" required maxlength="2000" placeholder="Short update shown across the site.">{{ old('message', $announcement->message) }}</textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            @foreach(config('promotions.announcement_types') as $key => $meta)
                                <option value="{{ $key }}" @selected(old('type', $announcement->type) === $key)>{{ $meta['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Style</label>
                        <select name="style" class="form-select" required>
                            @foreach(config('promotions.announcement_styles') as $key => $label)
                                <option value="{{ $key }}" @selected(old('style', $announcement->style) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Audience</label>
                        <select name="audience" class="form-select" required>
                            @foreach(config('promotions.audiences') as $key => $label)
                                <option value="{{ $key }}" @selected(old('audience', $announcement->audience) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CTA label (optional)</label>
                        <input type="text" name="cta_label" class="form-control" value="{{ old('cta_label', $announcement->cta_label) }}" maxlength="80" placeholder="Shop the offer">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CTA URL (optional)</label>
                        <input type="url" name="cta_url" class="form-control" value="{{ old('cta_url', $announcement->cta_url) }}" maxlength="500" placeholder="https://">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Priority (lower = higher)</label>
                        <input type="number" name="priority" class="form-control" value="{{ old('priority', $announcement->priority ?? 100) }}" min="1" max="9999">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Starts at</label>
                        <input type="datetime-local" name="starts_at" class="form-control"
                               value="{{ old('starts_at', optional($announcement->starts_at)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Ends at</label>
                        <input type="datetime-local" name="ends_at" class="form-control"
                               value="{{ old('ends_at', optional($announcement->ends_at)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                                @checked(old('is_active', $announcement->is_active))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_dismissible" value="1" id="is_dismissible"
                                @checked(old('is_dismissible', $announcement->is_dismissible))>
                            <label class="form-check-label" for="is_dismissible">Users can dismiss</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        {{ $mode === 'create' ? 'Create announcement' : 'Save changes' }}
                    </button>
                    <a href="{{ route('admin.promotions.announcements.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
