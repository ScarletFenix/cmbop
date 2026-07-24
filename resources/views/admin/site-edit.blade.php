@extends(staff_layout())

@section('title', 'Edit site')

@section('content')
@php
    $isMarketingEditor = $isMarketingEditor ?? false;
@endphp
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">{{ $isMarketingEditor ? 'Fill metrics & geo' : 'Edit site' }}</h4>
            <p class="text-muted mb-0 small">
                {{ $site->publisher?->name ?? 'Unknown publisher' }}
                @if($site->publisher?->email)
                    · {{ $site->publisher->email }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ url()->previous(staff_route('sites.index')) }}" class="btn btn-sm btn-outline-secondary">← Back</a>
            <a href="{{ staff_route('sites.index') }}" class="btn btn-sm btn-outline-primary">Sites list</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

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
                    Publisher already provided URL and price. Fill metrics and geo, then the publisher completes listing details for admin review.
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

                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="language">Language <span class="text-danger">*</span></label>
                            <select id="language" name="language" class="form-select @error('language') is-invalid @enderror" required>
                                <option value="">Select…</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old('language', strtolower((string) $site->language)) === strtolower($language->code))>
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
                                        @selected(old('country', strtolower((string) $site->country)) === strtolower($country->code))>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('country')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA <span class="text-danger">*</span></label>
                            <input type="number" id="da" name="da" class="form-control @error('da') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old('da', $site->da) }}">
                            @error('da')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR <span class="text-danger">*</span></label>
                            <input type="number" id="dr" name="dr" class="form-control @error('dr') is-invalid @enderror"
                                   min="0" max="100" step="1" required
                                   value="{{ old('dr', $site->dr) }}">
                            @error('dr')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic <span class="text-danger">*</span></label>
                            <input type="number" id="traffic" name="traffic" class="form-control @error('traffic') is-invalid @enderror"
                                   min="0" step="1" required
                                   value="{{ old('traffic', $site->traffic) }}">
                            @error('traffic')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i> Save metrics
                        </button>
                        <a href="{{ url()->previous(staff_route('sites.index')) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            @else
                <form method="POST" action="{{ staff_route('sites.update', $site->id) }}" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_name">Site name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="{{ old('site_name', $site->site_name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_url">Site URL</label>
                            <input type="url" id="site_url" name="site_url" class="form-control"
                                   value="{{ old('site_url', $site->site_url) }}" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="da">DA</label>
                            <input type="number" id="da" name="da" class="form-control" min="0" max="100"
                                   value="{{ old('da', $site->da) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="dr">DR</label>
                            <input type="number" id="dr" name="dr" class="form-control" min="0" max="100"
                                   value="{{ old('dr', $site->dr) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="traffic">Traffic</label>
                            <input type="number" id="traffic" name="traffic" class="form-control" min="0"
                                   value="{{ old('traffic', $site->traffic) }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="price">Price (€)</label>
                            <input type="number" id="price" name="price" class="form-control" min="0" step="0.01"
                                   value="{{ old('price', $site->price) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="language">Language</label>
                            <select id="language" name="language" class="form-select">
                                <option value="">Select…</option>
                                @foreach($languages as $language)
                                    <option value="{{ strtolower($language->code) }}"
                                        @selected(old('language', strtolower((string) $site->language)) === strtolower($language->code))>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
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

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="category">Category</label>
                            <input type="text" id="category" name="category" class="form-control"
                                   value="{{ old('category', $site->category) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="example_url">Example URL</label>
                            <input type="url" id="example_url" name="example_url" class="form-control"
                                   value="{{ old('example_url', $site->example_url) }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="publication_time">Publication time</label>
                            <input type="text" id="publication_time" name="publication_time" class="form-control"
                                   value="{{ old('publication_time', $site->publication_time) }}" placeholder="permanent">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="link_type">Link type</label>
                            <input type="text" id="link_type" name="link_type" class="form-control"
                                   value="{{ old('link_type', $site->link_type) }}" placeholder="dofollow">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="4">{{ old('description', $site->description) }}</textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="site_image">Site image</label>
                            <input type="file" id="site_image" name="site_image" class="form-control" accept="image/*">
                            <div class="form-text">Leave empty to keep the current image.</div>
                            @if($site->site_image)
                                <div class="mt-2">
                                    <img src="{{ asset('storage/'.$site->site_image) }}"
                                         alt="Current site image"
                                         style="max-width:120px;max-height:90px;border-radius:6px;border:1px solid #dee2e6;padding:3px;">
                                </div>
                            @endif
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
                        <a href="{{ url()->previous(staff_route('sites.index')) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            @endif
        </div>
    </div>

</div>
@endsection
