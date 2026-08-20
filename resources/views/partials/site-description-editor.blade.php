{{--
    Listing brief editor (same Quill Snow toolbar as publisher My Sites).

    @param string      $value
    @param string      $name
    @param bool        $required
    @param string      $label
    @param string|null $editorId
--}}
@php
    $descName = $name ?? 'description';
    $descValue = scalar_text($value ?? '');
    $descRequired = (bool) ($required ?? false);
    $descLabel = $label ?? 'Description';
    $descEditorId = $editorId ?? 'quillEditor';
    $descMin = (int) \App\Support\SiteDescriptionRules::MIN_CHARS;
    $descMaxChars = (int) \App\Support\SiteDescriptionRules::MAX_CHARS;
    $descMaxWords = (int) \App\Support\SiteDescriptionRules::MAX_WORDS;
    $descValue = app(\App\Services\SiteDescriptionSanitizer::class)->sanitize($descValue);
    $descHelp = $help ?? ('Shown to advertisers on the listing. Min '.$descMin.' characters, max '.$descMaxWords.' words.');
@endphp
<div class="site-description-editor"
     id="description"
     data-site-description-editor
     data-min-chars="{{ $descMin }}"
     data-max-chars="{{ $descMaxChars }}"
     data-max-words="{{ $descMaxWords }}"
     data-placeholder="{{ \App\Support\SiteDescriptionRules::placeholder() }}">
    <label class="form-label fw-semibold" for="{{ $descEditorId }}">
        {{ $descLabel }}
        @if($descRequired)
            <span class="text-danger">*</span>
        @endif
    </label>
    <div id="{{ $descEditorId }}"
         class="site-description-editor__surface"
         data-site-desc-surface>{!! $descValue !!}</div>
    <textarea name="{{ $descName }}"
              id="{{ $descName }}-input"
              class="site-description-editor__input"
              hidden>{{ $descValue }}</textarea>
    <div class="site-desc-meta">
        <span class="site-desc-counter" data-site-desc-counter></span>
        <span class="form-text mb-0">{{ $descHelp }}</span>
    </div>
    @error($descName)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
@once
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    @push('scripts')
        <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
        <script src="{{ asset('assets/js/site-description-editor.js') }}?v={{ @filemtime(public_path('assets/js/site-description-editor.js')) ?: '1' }}"></script>
    @endpush
@endonce
