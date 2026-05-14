@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-6">
            <h1 class="h3 mb-0">Edit Blog</h1>
            <p class="text-muted">Update your blog post</p>
        </div>
        <div class="col-md-6 text-end">
            <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary">
                <i class="fa fa-arrow-left me-2"></i> Back to Blogs
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <strong>Please fix the following errors:</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form action="{{ route('admin.blogs.update', $blog->id) }}" method="POST" enctype="multipart/form-data" id="blogForm">
                @csrf
                @method('PUT')

                <div class="row">
                    <div class="col-md-8">
                        <!-- Title -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control form-control-lg @error('title') is-invalid @enderror" value="{{ old('title', $blog->title) }}" required>
                            @error('title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Content with Quill -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Content <span class="text-danger">*</span></label>
                            <div id="quillEditor" class="border rounded" style="height: 400px; background: white;"></div>
                            <input type="hidden" name="content" id="contentInput">
                            @error('content')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-4">
                        <!-- Featured Image -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Featured Image</label>
                            <div class="border rounded p-3 text-center" style="background: #f8f9fa;">
                                <div id="featuredImagePreview" class="mb-2">
                                    @if($blog->featured_image)
                                        <img src="{{ Storage::url($blog->featured_image) }}" alt="Current Image" class="img-fluid rounded" style="max-height: 150px;">
                                    @else
                                        <div id="noImagePlaceholder">
                                            <i class="fa fa-image fa-3x text-muted mb-2"></i>
                                            <p class="text-muted small">No image selected</p>
                                        </div>
                                    @endif
                                </div>
                                <input type="file" name="featured_image" id="featuredImageInput" class="d-none" accept="image/*">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('featuredImageInput').click()">
                                    <i class="fa fa-upload me-1"></i> Change Image
                                </button>
                                <small class="text-muted d-block mt-2">JPG, PNG, GIF, WEBP (max 5MB)</small>
                            </div>
                            @error('featured_image')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Tags -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tags</label>
                            <input type="text" name="tags" class="form-control @error('tags') is-invalid @enderror" value="{{ old('tags', $blog->formatted_tags) }}" placeholder="laravel, php, web development">
                            <small class="text-muted">Comma-separated tags</small>
                            @error('tags')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                <option value="draft" {{ old('status', $blog->status) == 'draft' ? 'selected' : '' }}>Draft</option>
                                <option value="published" {{ old('status', $blog->status) == 'published' ? 'selected' : '' }}>Published</option>
                            </select>
                            @error('status')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
                        <i class="fa fa-save me-2"></i> Update Blog
                    </button>
                    <a href="{{ route('admin.blogs.index') }}" class="btn btn-secondary px-4">
                        <i class="fa fa-times me-2"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quill & SweetAlert -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Initialize Quill
var quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'Write your blog content here...',
    modules: {
        toolbar: [
            [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'color': [] }, { 'background': [] }],
            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
            ['link', 'image', 'video'],
            ['clean']
        ]
    }
});

// Load existing content into Quill
var existingContent = @json(old('content', $blog->content));
if (existingContent) {
    quill.root.innerHTML = existingContent;
}

// Featured image preview
document.getElementById('featuredImageInput').addEventListener('change', function(evt) {
    const [file] = this.files;
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('featuredImagePreview').innerHTML = `<img src="${e.target.result}" alt="Preview" class="img-fluid rounded" style="max-height: 150px;">`;
        }
        reader.readAsDataURL(file);
    }
});

// On submit: set content into hidden input
var form = document.getElementById('blogForm');
form.addEventListener('submit', function(e) {
    var content = quill.root.innerHTML.trim();
    document.getElementById('contentInput').value = content;

    if (!content || content === '<p><br></p>' || content === '<p></p>') {
        e.preventDefault();
        Swal.fire('Error', 'Please enter some content before submitting.', 'error');
        return false;
    }
});
</script>
@endsection