@extends(staff_layout())

@section('title', 'Add site for publisher')

@section('content')
@php
    $categories = $categories ?? collect();
    $rawNiches = old('categories', []);
    if (is_string($rawNiches)) {
        $rawNiches = preg_split('/\|/', $rawNiches) ?: [];
    }
    $prefillNiches = \App\Models\Category::resolveNicheNames($rawNiches)['resolved'] ?? [];
    if (is_string($prefillNiches)) {
        $prefillNiches = array_values(array_filter(array_map('trim', preg_split('/\|/', $prefillNiches) ?: [])));
    }
    $prefillNiches = collect($prefillNiches)->filter(fn ($v) => filled($v))->values()->all();
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Add site for publisher</h4>
            <p class="text-muted mb-0 small">
                Create a full listing. The publisher gets email + bell, accepts it into My Sites, then you Activate / Deactivate as usual.
                TXT verification for the Verified badge works the same as other sites after accept.
                See the <a href="{{ staff_route('staff-handbook') }}">{{ __('messages.staff_handbook_title') }}</a>.
            </p>
        </div>
        <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-secondary">← Back to Sites</a>
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
            <form method="POST" action="{{ staff_route('sites.store') }}" enctype="multipart/form-data" id="staffAssignSiteForm">
                @csrf

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="publisher_id">Publisher <span class="text-danger">*</span></label>
                        <select id="publisher_id" name="publisher_id" class="form-select @error('publisher_id') is-invalid @enderror" required>
                            <option value="">Select publisher…</option>
                            @foreach($publishers as $publisher)
                                <option value="{{ $publisher->id }}"
                                    @selected((int) old('publisher_id', $selectedPublisherId) === (int) $publisher->id)>
                                    {{ $publisher->name }} · {{ $publisher->email }}
                                </option>
                            @endforeach
                        </select>
                        @error('publisher_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_name">Site name <span class="text-danger">*</span></label>
                        <input type="text" id="site_name" name="site_name" class="form-control @error('site_name') is-invalid @enderror"
                               value="{{ old_text('site_name') }}" required maxlength="255">
                        @error('site_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_url">Site URL <span class="text-danger">*</span></label>
                        <input type="text" id="site_url" name="site_url" class="form-control @error('site_url') is-invalid @enderror"
                               value="{{ old_text('site_url') }}" required placeholder="https://example.com">
                        @error('site_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="example_url">Example post URL <span class="text-danger">*</span></label>
                        <input type="text" id="example_url" name="example_url" class="form-control @error('example_url') is-invalid @enderror"
                               value="{{ old_text('example_url') }}" required placeholder="https://example.com/sample-post">
                        @error('example_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="price">Price (€) <span class="text-danger">*</span></label>
                        <input type="number" id="price" name="price" class="form-control @error('price') is-invalid @enderror"
                               min="0" step="0.01" required value="{{ old_text('price') }}">
                        @error('price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                        <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                               min="0" max="100" step="1" required value="{{ old_text('da') }}">
                        @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                        <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                               min="0" max="100" step="1" required value="{{ old_text('dr') }}">
                        @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                        <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                               min="0" max="4294967295" step="1" inputmode="numeric" required
                               placeholder="e.g. 1500000" value="{{ old_text('traffic') }}">
                        @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                        <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                            <option value="">Select…</option>
                            @foreach($languages as $language)
                                <option value="{{ strtolower($language->code) }}"
                                    @selected(old('language') === strtolower($language->code))>
                                    {{ $language->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('language')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="country">Country <span class="text-danger">*</span></label>
                        <select id="country" name="country" class="form-select @error('country') is-invalid @enderror" required>
                            <option value="">Select…</option>
                            @foreach($countries as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(old('country') === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="categoryInput">Niches <span class="text-danger">*</span> (max 7)</label>
                        <input type="hidden" name="categories" id="selectedCategories" value="{{ implode('|', $prefillNiches) }}">
                        <div class="multi-select-wrapper" id="categoryWrapper">
                            <div class="multi-select-input" id="categoryInput" role="button" tabindex="0" aria-haspopup="listbox">
                                <span class="multi-select-placeholder">Select niches (max 7)…</span>
                            </div>
                            <div class="multi-select-dropdown" id="categoryDropdown" role="listbox">
                                <div class="multi-select-search">
                                    <input type="text" placeholder="Search niches…" id="categorySearch" autocomplete="off">
                                </div>
                                <div class="multi-select-options" id="categoryOptions">
                                    @foreach($categories as $category)
                                        <div class="multi-select-option"
                                             data-value="{{ $category->name }}"
                                             data-label="{{ $category->name }}">{{ $category->name }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="form-text">Click niches one by one — no Ctrl needed. Max 7.</div>
                        @error('categories')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="turnaround_time">Turnaround <span class="text-danger">*</span></label>
                        <select id="turnaround_time" name="turnaround_time" class="form-select @error('turnaround_time') is-invalid @enderror" required>
                            @foreach(['24h' => '24 hours', '48h' => '48 hours', '3days' => '3 days', '5days' => '5 days', '7days' => '7 days'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('turnaround_time', '3days') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('turnaround_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="publication_time">Publication time <span class="text-danger">*</span></label>
                        <select id="publication_time" name="publication_time" class="form-select @error('publication_time') is-invalid @enderror" required>
                            @foreach(['6months' => '6 months', '1year' => '1 year', 'permanent' => 'Permanent'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('publication_time', 'permanent') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('publication_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="link_type">Link type <span class="text-danger">*</span></label>
                        <select id="link_type" name="link_type" class="form-select @error('link_type') is-invalid @enderror" required>
                            <option value="dofollow" @selected(old('link_type', 'dofollow') === 'dofollow')>Dofollow</option>
                            <option value="nofollow" @selected(old('link_type') === 'nofollow')>Nofollow</option>
                        </select>
                        @error('link_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold d-block">Site tag</label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(['as_you_prefer' => 'As you prefer', 'sponsored' => 'Sponsored', 'partner_material' => 'Partner material'] as $value => $label)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="site_tag" id="tag_{{ $value }}"
                                           value="{{ $value }}" @checked(old('site_tag', 'as_you_prefer') === $value)>
                                    <label class="form-check-label" for="tag_{{ $value }}">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="site_image">Site image</label>
                        <input type="file" id="site_image" name="site_image"
                               class="form-control @error('site_image') is-invalid @enderror"
                               accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                               data-max-kb="10240">
                        <div class="form-text">Optional desktop screenshot (JPEG, PNG, GIF, or WebP up to 10&nbsp;MB).</div>
                        @error('site_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold" for="description">Description <span class="text-danger">*</span></label>
                        <textarea id="description" name="description" rows="5"
                                  class="form-control @error('description') is-invalid @enderror"
                                  required minlength="50">{{ old_text('description') }}</textarea>
                        <div class="form-text">At least 50 characters.</div>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-plus me-1"></i> Add site &amp; notify publisher
                    </button>
                    <a href="{{ staff_route('sites.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script>
(function () {
    const prefills = @json($prefillNiches);
    const ms = window.initMultiSelect({
        wrapperId: 'categoryWrapper',
        inputId: 'categoryInput',
        dropdownId: 'categoryDropdown',
        optionsId: 'categoryOptions',
        hiddenInputId: 'selectedCategories',
        searchId: 'categorySearch',
        maxSelections: 7,
        placeholderText: 'Select niches (max 7)…',
    });
    if (ms && prefills.length) {
        ms.setSelectedItems(prefills, prefills);
    }

    const form = document.getElementById('staffAssignSiteForm');
    const hidden = document.getElementById('selectedCategories');
    if (form && hidden) {
        form.addEventListener('submit', function (e) {
            if (!String(hidden.value || '').trim()) {
                e.preventDefault();
                if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select at least one niche', timer: 2200, showConfirmButton: false });
                } else {
                    alert('Select at least one niche');
                }
            }
        });
    }
})();
</script>
@endsection
