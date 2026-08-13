{{--
    Filled Upload article CTA. Pass $uploadButtonId for the page-level control
    (tests assert openUploadModalBtn). Empty states omit it so the id stays unique.
--}}
@php
    $uploadButtonId = $uploadButtonId ?? null;
    $enabled = $uploadsEnabled ?? true;
@endphp
@if($enabled)
    <button type="button"
            class="btn btn-upload"
            data-bs-toggle="modal"
            data-bs-target="#uploadContentModal"
            @if($uploadButtonId) id="{{ $uploadButtonId }}" @endif>
        <span class="btn-upload__icon" aria-hidden="true"><i class="fa fa-upload"></i></span>
        <span class="btn-upload__label">Upload article</span>
        <span class="btn-upload__hint">.docx</span>
    </button>
@else
    <button type="button"
            class="btn btn-upload"
            @if($uploadButtonId) id="{{ $uploadButtonId }}" @endif
            disabled
            title="Uploads are temporarily turned off">
        <span class="btn-upload__icon" aria-hidden="true"><i class="fa fa-upload"></i></span>
        <span class="btn-upload__label">Uploads disabled</span>
    </button>
@endif
