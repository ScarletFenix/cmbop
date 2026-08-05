{{--
    Image rights declaration.

    Required on every upload: a copyright complaint has to be traceable to what
    the uploader asserted, and we cannot know whether a .docx contains images
    until after it is parsed. Shared by the Content Library modal and the
    checkout upload wizard, so both paths record the same declaration.

    Expects:
      $idPrefix   unique per form instance (ids must not collide on a page)
      $submission optional ContentSubmission to prefill from
--}}
@php
    $prefix = $idPrefix ?? 'imageRights';
    $current = $submission->image_rights ?? null;
    $currentSource = $submission->image_rights_source ?? '';
@endphp

<fieldset class="image-rights-fieldset mb-3" data-image-rights>
    <legend class="form-label mb-1">
        Image rights <span class="text-danger">*</span>
    </legend>
    <p class="form-text mt-0 mb-2">
        If your article uses pictures, tell us where they came from. We cannot publish images we have no right to use.
    </p>

    <div class="form-check">
        <input class="form-check-input" type="radio" name="image_rights"
               id="{{ $prefix }}Own" value="own" data-image-rights-choice
               @checked($current === 'own')>
        <label class="form-check-label" for="{{ $prefix }}Own">
            I own or created the images
            <span class="d-block text-muted small">My own photography, graphics, or images I hold a licence for.</span>
        </label>
    </div>

    <div class="form-check">
        <input class="form-check-input" type="radio" name="image_rights"
               id="{{ $prefix }}Licensed" value="licensed" data-image-rights-choice
               @checked($current === 'licensed')>
        <label class="form-check-label" for="{{ $prefix }}Licensed">
            The images come from another source
            <span class="d-block text-muted small">Stock library, client, or another site — give us the source below.</span>
        </label>
    </div>

    <div class="mt-2 ms-4 {{ $current === 'licensed' ? '' : 'd-none' }}" data-image-rights-source-wrap>
        <label class="form-label small mb-1" for="{{ $prefix }}Source">
            Source URL or copyright / licence details <span class="text-danger">*</span>
        </label>
        <input type="text" class="form-control form-control-sm" name="image_rights_source"
               id="{{ $prefix }}Source" maxlength="2000"
               placeholder="https://unsplash.com/photos/... or: licensed from Getty, licence #12345"
               value="{{ $currentSource }}"
               data-image-rights-source>
        <div class="form-text">A link is enough. If there is no link, name the licence or who granted permission.</div>
    </div>

    <div class="form-check mt-2">
        <input class="form-check-input" type="radio" name="image_rights"
               id="{{ $prefix }}None" value="none" data-image-rights-choice
               @checked($current === 'none')>
        <label class="form-check-label" for="{{ $prefix }}None">
            This article has no images
            <span class="d-block text-muted small">You will be asked again if you add images while editing.</span>
        </label>
    </div>
</fieldset>
