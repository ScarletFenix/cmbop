/**
 * Image rights declaration behaviour.
 *
 * Shared by the Content Library upload modal and the checkout upload wizard, so
 * both collect the same declaration. Delegated listeners only, because the
 * wizard renders its cards after page load.
 */
(function (global) {
    'use strict';

    var SOURCE_REQUIRED_VALUE = 'licensed';

    function fieldsetFor(el) {
        return el ? el.closest('[data-image-rights]') : null;
    }

    /* Show the source input only when the choice needs one, and keep `required`
       in step so the browser does not block a hidden field. */
    function syncFieldset(fieldset) {
        if (!fieldset) return;

        var checked = fieldset.querySelector('[data-image-rights-choice]:checked');
        var wrap = fieldset.querySelector('[data-image-rights-source-wrap]');
        var input = fieldset.querySelector('[data-image-rights-source]');
        var needsSource = !!checked && checked.value === SOURCE_REQUIRED_VALUE;

        if (wrap) wrap.classList.toggle('d-none', !needsSource);
        if (input) {
            input.required = needsSource;
            if (!needsSource) input.value = '';
        }
    }

    document.addEventListener('change', function (e) {
        var choice = e.target.closest ? e.target.closest('[data-image-rights-choice]') : null;
        if (!choice) return;
        syncFieldset(fieldsetFor(choice));
    });

    /**
     * Read a declaration for submitting. Returns null with a message when the
     * advertiser has not answered, so callers can show their own feedback
     * instead of relying on a server 422.
     *
     * @returns {{ok: boolean, message?: string, rights?: string, source?: string}}
     */
    global.readImageRights = function readImageRights(scope) {
        var fieldset = (scope || document).querySelector('[data-image-rights]');
        if (!fieldset) {
            return { ok: false, message: 'Image rights declaration is missing from this form.' };
        }

        var checked = fieldset.querySelector('[data-image-rights-choice]:checked');
        if (!checked) {
            return { ok: false, message: 'Tell us where the images in this article came from.' };
        }

        var source = '';
        if (checked.value === SOURCE_REQUIRED_VALUE) {
            var input = fieldset.querySelector('[data-image-rights-source]');
            source = input ? String(input.value || '').trim() : '';
            if (!source) {
                return { ok: false, message: 'Add the source URL or copyright/licence details for the images.' };
            }
        }

        return { ok: true, rights: checked.value, source: source };
    };

    /* Append the declaration to a FormData built by hand. */
    global.appendImageRights = function appendImageRights(formData, scope) {
        var result = global.readImageRights(scope);
        if (!result.ok) return result;

        formData.append('image_rights', result.rights);
        if (result.source) {
            formData.append('image_rights_source', result.source);
        }

        return result;
    };

    function syncAll() {
        document.querySelectorAll('[data-image-rights]').forEach(syncFieldset);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncAll);
    } else {
        syncAll();
    }
})(typeof window !== 'undefined' ? window : this);
