@extends(staff_layout())

@section('title', 'Edit site')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h4 class="mb-1 fw-bold">Edit site</h4>
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
                        <input type="text" id="language" name="language" class="form-control"
                               value="{{ old('language', $site->language) }}" placeholder="en">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="country">Country</label>
                        <input type="text" id="country" name="country" class="form-control"
                               value="{{ old('country', $site->country) }}" placeholder="us">
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
        </div>
    </div>

</div>
@endsection
