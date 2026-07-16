@extends('admin.layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 920px;">
    <div class="mb-4">
        <a href="{{ route('admin.promotions.banners.index') }}" class="text-decoration-none small text-muted">
            <i class="fa fa-arrow-left me-1"></i> Back to banners
        </a>
        <h1 class="h3 mb-1 mt-2">{{ $mode === 'create' ? 'New Ad Banner' : 'Edit Ad Banner' }}</h1>
        <p class="text-muted mb-0">Choose a size that fits your website slot, then upload the creative.</p>
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
            <form method="POST" enctype="multipart/form-data"
                  action="{{ $mode === 'create' ? route('admin.promotions.banners.store') : route('admin.promotions.banners.update', $banner) }}">
                @csrf
                @if($mode === 'edit')
                    @method('PUT')
                @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Internal name</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $banner->name) }}" required maxlength="120" placeholder="BF25 marketplace rectangle">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Display title (optional)</label>
                        <input type="text" name="title" class="form-control" value="{{ old('title', $banner->title) }}" maxlength="160">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Size preset</label>
                        <select name="size_key" id="size_key" class="form-select" required>
                            @foreach(config('promotions.banner_sizes') as $key => $size)
                                <option value="{{ $key }}"
                                    data-width="{{ $size['width'] }}"
                                    data-height="{{ $size['height'] }}"
                                    @selected(old('size_key', $banner->size_key) === $key)>
                                    {{ $size['label'] }}
                                    @if($key !== 'custom') — {{ $size['width'] }}×{{ $size['height'] }} @endif
                                    ({{ $size['hint'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3" id="customWidthWrap">
                        <label class="form-label">Width (px)</label>
                        <input type="number" name="width" id="banner_width" class="form-control" value="{{ old('width', $banner->width) }}" min="20" max="2000">
                    </div>
                    <div class="col-md-3" id="customHeightWrap">
                        <label class="form-label">Height (px)</label>
                        <input type="number" name="height" id="banner_height" class="form-control" value="{{ old('height', $banner->height) }}" min="20" max="2000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Placement</label>
                        <select name="placement" class="form-select" required>
                            @foreach(config('promotions.banner_placements') as $key => $label)
                                <option value="{{ $key }}" @selected(old('placement', $banner->placement) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Audience</label>
                        <select name="audience" class="form-select" required>
                            @foreach(config('promotions.audiences') as $key => $label)
                                <option value="{{ $key }}" @selected(old('audience', $banner->audience) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Image upload</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div class="form-text">JPEG/PNG/WebP/GIF, max 5MB. Match the size preset when possible.</div>
                        @if($banner->imageSrc())
                            <img src="{{ $banner->imageSrc() }}" alt="Current banner" class="mt-2 rounded border" style="max-width:220px;max-height:120px;object-fit:contain;">
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Or image URL</label>
                        <input type="url" name="image_url" class="form-control" value="{{ old('image_url', $banner->image_url) }}" placeholder="https://cdn.example.com/banner.png">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Click-through URL</label>
                        <input type="url" name="link_url" class="form-control" value="{{ old('link_url', $banner->link_url) }}" placeholder="https://">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Alt text</label>
                        <input type="text" name="alt_text" class="form-control" value="{{ old('alt_text', $banner->alt_text) }}" maxlength="160">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Priority (lower = higher)</label>
                        <input type="number" name="priority" class="form-control" value="{{ old('priority', $banner->priority ?? 100) }}" min="1" max="9999">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Starts at</label>
                        <input type="datetime-local" name="starts_at" class="form-control"
                               value="{{ old('starts_at', optional($banner->starts_at)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Ends at</label>
                        <input type="datetime-local" name="ends_at" class="form-control"
                               value="{{ old('ends_at', optional($banner->ends_at)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                                @checked(old('is_active', $banner->is_active))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="open_in_new_tab" value="1" id="open_in_new_tab"
                                @checked(old('open_in_new_tab', $banner->open_in_new_tab))>
                            <label class="form-check-label" for="open_in_new_tab">Open link in new tab</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        {{ $mode === 'create' ? 'Create banner' : 'Save changes' }}
                    </button>
                    <a href="{{ route('admin.promotions.banners.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const select = document.getElementById('size_key');
    const width = document.getElementById('banner_width');
    const height = document.getElementById('banner_height');

    function syncSize() {
        const opt = select.options[select.selectedIndex];
        const isCustom = select.value === 'custom';
        const w = parseInt(opt.dataset.width || '0', 10);
        const h = parseInt(opt.dataset.height || '0', 10);
        if (!isCustom && w && h) {
            width.value = w;
            height.value = h;
            width.readOnly = true;
            height.readOnly = true;
        } else {
            width.readOnly = false;
            height.readOnly = false;
        }
    }

    select.addEventListener('change', syncSize);
    syncSize();
})();
</script>
@endsection
