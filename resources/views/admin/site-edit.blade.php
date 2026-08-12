@extends(staff_layout())

@section('title', 'Edit site')

@section('content')
@php
    $isMarketingEditor = $isMarketingEditor ?? false;
    $categories = $categories ?? collect();
    $rawMarketingNiches = old('categories', $site->categories_array ?? []);
    if (is_string($rawMarketingNiches)) {
        $rawMarketingNiches = preg_split('/\|/', $rawMarketingNiches) ?: [];
    }
    $marketingNiches = \App\Models\Category::resolveNicheNames($rawMarketingNiches)['resolved'];
    // Never re-inject unresolved labels (e.g. group "Technology") into the form.
    if (is_string($marketingNiches)) {
        $marketingNiches = array_values(array_filter(array_map('trim', preg_split('/\|/', $marketingNiches) ?: [])));
    }
    $marketingNiches = collect($marketingNiches)
        ->filter(fn ($v) => filled($v) && strtolower((string) $v) !== 'pending')
        ->values()
        ->all();
    // After save, url()->previous() is this edit page — Back would look broken.
    $sitesBackUrl = staff_route('sites.index', array_filter([
        'publisher' => $site->publisher_id,
        'site' => $site->id,
    ]));
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">{{ $isMarketingEditor ? 'Fill metrics, geo & niches' : 'Edit site' }}</h4>
            <p class="text-muted mb-0 small">
                {{ $site->publisher?->name ?? 'Unknown publisher' }}
                @if($site->publisher?->email)
                    · {{ $site->publisher->email }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ $sitesBackUrl }}" class="btn btn-sm btn-outline-secondary">← Back</a>
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-primary">Sites list</a>
        </div>
    </div>


    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @if($isMarketingEditor)
                <div class="alert alert-info border-0 mb-4">
                    Publisher already provided URL and price. Fill metrics, geo, and niches, then the publisher completes listing details for admin review.
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="text-muted small">Website</div>
                        <div class="fw-semibold text-break">{{ $site->domain ?: $site->site_name }}</div>
                        <a class="small text-muted text-break" href="{{ $site->site_url }}" target="_blank" rel="noopener noreferrer">
                            {{ $site->site_url }}
                        </a>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Price</div>
                        <div class="fw-semibold">€{{ number_format((float) $site->price, 2) }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Status</div>
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <span class="badge {{ $site->verified ? 'bg-success' : 'bg-warning text-dark' }}">
                                {{ $site->verified ? 'Verified' : 'Unverified' }}
                            </span>
                            <span class="badge {{ $site->active ? 'bg-primary' : 'bg-secondary' }}">
                                {{ $site->active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="country">Country <span class="text-danger">*</span></label>
                            <select id="country" name="country" class="form-select @error('country') is-invalid @enderror" required>
                                <option value="">Select…</option>
                                @foreach($countries as $country)
                                    <option value="{{ strtolower($country->code) }}"
                                        @selected(old('country', strtolower((string) $site->country)) === strtolower($country->code))>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Pick country first.</div>
                            @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                            <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                                <option value="">Select country first</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old('language', strtolower((string) $site->language)) === strtolower($language->code))>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Only languages paired with that country.</div>
                            @error('language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                            <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old_text('da', $site->da) }}">
                            @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                            <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old_text('dr', $site->dr) }}">
                            @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                            <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                                   min="0" max="4294967295" step="1" inputmode="numeric" required
                                   placeholder="e.g. 1500000"
                                   value="{{ old_text('traffic', $site->traffic) }}">
                            @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="categoryInput">Niches <span class="text-danger">*</span> (max 7)</label>
                            <input type="hidden"
                                   name="categories"
                                   id="selectedCategories"
                                   value="{{ implode('|', $marketingNiches) }}">
                            <div class="multi-select-wrapper" id="categoryWrapper" data-multi-select="category">
                                <div class="multi-select-input" id="categoryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select niches">
                                    <span class="multi-select-placeholder">Select niches (max 7)…</span>
                                </div>
                                <div class="multi-select-dropdown" id="categoryDropdown" role="listbox" aria-multiselectable="true">
                                    <div class="multi-select-search">
                                        <input type="text" placeholder="Type to search niches…" id="categorySearch" autocomplete="off" aria-label="Search niches">
                                    </div>
                                    <div class="multi-select-options" id="categoryOptions">
                                        @foreach($categories as $categoryName)
                                            <div class="multi-select-option"
                                                 role="option"
                                                 data-value="{{ $categoryName }}"
                                                 data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                        @endforeach
                                    </div>
                                    <div class="multi-select-empty d-none" id="categoryEmpty" role="status">No categories found</div>
                                </div>
                            </div>
                            <div class="form-text">Same niches as Catalog. Type and press Enter to add; Backspace removes the last chip. Max 7.</div>
                            @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="site_image">Site image</label>
                            <input type="file" id="site_image" name="site_image"
                                   class="form-control @error('site_image') is-invalid @enderror"
                                   accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                                   data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}">
                            <div class="form-text">Optional desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB). Leave empty to keep the current image.</div>
                            @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="siteImagePreview"
                                 class="site-image-desktop-preview {{ $site->site_image ? '' : 'is-empty' }}"
                                 data-existing="{{ $site->site_image ? '/storage/'.$site->site_image : '' }}">
                                @if($site->site_image)
                                    <img src="{{ '/storage/'.$site->site_image }}" alt="Current site image">
                                @else
                                    <span>No image yet — choose a desktop-size screenshot (16:10)</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i> Save metrics &amp; niches
                        </button>
                        <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>

                <link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
                <script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
                <script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
                <script>
                (function () {
                    const prefills = @json($marketingNiches);
                    const ms = window.initMultiSelect({
                        wrapperId: 'categoryWrapper',
                        inputId: 'categoryInput',
                        dropdownId: 'categoryDropdown',
                        optionsId: 'categoryOptions',
                        hiddenInputId: 'selectedCategories',
                        searchId: 'categorySearch',
                        emptyId: 'categoryEmpty',
                        maxSelections: 7,
                        placeholderText: 'Select niches (max 7)…',
                    });
                    if (ms && prefills.length) {
                        ms.setSelectedItems(prefills, prefills);
                    }
                    const form = document.querySelector('form[action*="sites"]');
                    const hidden = document.getElementById('selectedCategories');
                    const imageInput = document.getElementById('site_image');
                    if (form && hidden) {
                        form.addEventListener('submit', function (e) {
                            if (!String(hidden.value || '').trim()) {
                                e.preventDefault();
                                if (window.Swal) {
                                    Swal.fire({ icon: 'warning', title: 'Select at least one niche', timer: 2200, showConfirmButton: false });
                                } else {
                                    slbAlert({ icon: 'warning', title: 'Select at least one niche' });
                                }
                                return;
                            }
                            if (imageInput && imageInput.files && imageInput.files[0]) {
                                const maxKb = parseInt(imageInput.getAttribute('data-max-kb') || '10240', 10);
                                const maxBytes = maxKb * 1024;
                                if (imageInput.files[0].size > maxBytes) {
                                    e.preventDefault();
                                    const mb = Math.floor(maxKb / 1024);
                                    const title = 'Site image must be under ' + mb + ' MB';
                                    if (window.Swal) {
                                        Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
                                    } else {
                                        slbAlert({ icon: 'warning', title: title });
                                    }
                                }
                            }
                        });
                    }
                })();
                </script>
            @else
                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_name">Site name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="{{ old_text('site_name', $site->site_name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_url">Site URL</label>
                            <input type="url" id="site_url" name="site_url" class="form-control"
                                   value="{{ old_text('site_url', $site->site_url) }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA</label>
                            <input type="number" id="da" name="da" class="form-control" min="0" max="100"
                                   value="{{ old_text('da', $site->da) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR</label>
                            <input type="number" id="dr" name="dr" class="form-control" min="0" max="100"
                                   value="{{ old_text('dr', $site->dr) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic</label>
                            <input type="number" id="traffic" name="traffic" class="form-control" min="0" max="4294967295"
                                   step="1" inputmode="numeric" placeholder="e.g. 1500000"
                                   value="{{ old_text('traffic', $site->traffic) }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="price">Price (€)</label>
                            <input type="number" id="price" name="price" class="form-control" min="0" step="0.01"
                                   value="{{ old_text('price', $site->price) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="country">Country</label>
                            <select id="country" name="country" class="form-select">
                                <option value="">Select…</option>
                                @foreach($countries as $country)
                                    <option value="{{ strtolower($country->code) }}"
                                        @selected(old('country', strtolower((string) $site->country)) === strtolower($country->code))>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="language">Language</label>
                            <select id="language" name="language" class="form-select">
                                <option value="">Select country first</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old('language', strtolower((string) $site->language)) === strtolower($language->code))>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="category">Category</label>
                            <input type="text" id="category" name="category" class="form-control"
                                   value="{{ old_text('category', $site->category) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="example_url">Example URL</label>
                            <input type="url" id="example_url" name="example_url" class="form-control"
                                   value="{{ old_text('example_url', $site->example_url) }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="publication_time">Publication time</label>
                            <input type="text" id="publication_time" name="publication_time" class="form-control"
                                   value="{{ old_text('publication_time', $site->publication_time) }}" placeholder="permanent">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="link_type">Link type</label>
                            <input type="text" id="link_type" name="link_type" class="form-control"
                                   value="{{ old_text('link_type', $site->link_type) }}" placeholder="dofollow">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="4">{{ old_text('description', $site->description) }}</textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="site_image">Site image</label>
                            <input type="file" id="site_image" name="site_image"
                                   class="form-control @error('site_image') is-invalid @enderror"
                                   accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                                   data-max-kb="{{ \App\Support\SiteImageUpload::maxKilobytes() }}">
                            <div class="form-text">Desktop screenshot (JPEG, PNG, GIF, or WebP up to {{ \App\Support\SiteImageUpload::maxMegabytesLabel() }}&nbsp;MB). Leave empty to keep the current image.</div>
                            @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="siteImagePreview"
                                 class="site-image-desktop-preview {{ $site->site_image ? '' : 'is-empty' }}"
                                 data-existing="{{ $site->site_image ? '/storage/'.$site->site_image : '' }}">
                                @if($site->site_image)
                                    <img src="{{ '/storage/'.$site->site_image }}" alt="Current site image">
                                @else
                                    <span>No image yet — choose a desktop-size screenshot (16:10)</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold d-block">Status</label>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge {{ $site->verified ? 'bg-success' : 'bg-warning text-dark' }}">
                                    {{ $site->verified ? 'Verified' : 'Unverified' }}
                                </span>
                                <span class="badge {{ $site->active ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ $site->active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <div class="form-text mt-2">Verify / activate are admin-only.</div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i> Save changes
                        </button>
                        <a href="{{ $sitesBackUrl }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            @endif
        </div>
    </div>

</div>

<script>
(function () {
    const map = @json($countryLanguageMap ?? new \stdClass());
    const countryEl = document.getElementById('country');
    const langEl = document.getElementById('language');
    const preferredLang = @json(old('language', strtolower((string) ($site->language ?? ''))));

    function refreshLanguages() {
        if (!countryEl || !langEl) return;
        const code = (countryEl.value || '').toLowerCase();
        const list = map[code] || [];
        const keep = (langEl.value || preferredLang || '').toLowerCase();
        langEl.innerHTML = '';
        if (!code) {
            langEl.disabled = true;
            langEl.innerHTML = '<option value="">Select country first</option>';
            return;
        }
        langEl.disabled = false;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select…';
        langEl.appendChild(placeholder);
        list.forEach(function (row) {
            const opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name || String(row.code).toUpperCase();
            if (keep && keep === String(row.code).toLowerCase()) opt.selected = true;
            langEl.appendChild(opt);
        });
        if (list.length === 1 && !langEl.value) {
            langEl.value = list[0].code;
        }
    }

    if (countryEl) {
        countryEl.addEventListener('change', refreshLanguages);
        refreshLanguages();
    }
})();
</script>
<script>
(function () {
    const imageInput = document.getElementById('site_image');
    const preview = document.getElementById('siteImagePreview');
    if (!imageInput || !preview) return;

    const existingSrc = preview.getAttribute('data-existing') || '';

    function showExistingOrEmpty() {
        if (existingSrc) {
            preview.classList.remove('is-empty');
            preview.innerHTML = '<img src="' + existingSrc + '" alt="Current site image">';
        } else {
            preview.classList.add('is-empty');
            preview.innerHTML = '<span>No image yet — choose a desktop-size screenshot (16:10)</span>';
        }
    }

    imageInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) {
            showExistingOrEmpty();
            return;
        }
        const maxKb = parseInt(imageInput.getAttribute('data-max-kb') || '10240', 10);
        if (file.size > maxKb * 1024) {
            this.value = '';
            showExistingOrEmpty();
            const title = 'Site image must be under ' + Math.floor(maxKb / 1024) + ' MB';
            if (window.Swal) {
                Swal.fire({ icon: 'warning', title: title, timer: 2800, showConfirmButton: false });
            } else if (window.slbAlert) {
                slbAlert({ icon: 'warning', title: title });
            }
            return;
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.classList.remove('is-empty');
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Selected site image">';
        };
        reader.readAsDataURL(file);
    });
})();
</script>
@endsection
