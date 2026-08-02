/**
 * Shared AJAX failure handling.
 *
 * Every page used to invent its own `error:` / `.catch()` block, so a expired
 * session, a permission problem and a validation failure all surfaced as
 * "Something went wrong". This maps the status codes users actually hit to
 * copy that tells them what to do next.
 *
 *   slbHttpMessage(source, fallback)  -> string
 *   slbHandleHttpError(source, opts)  -> string (also shows a toast/alert)
 *
 * `source` may be a jQuery jqXHR, a fetch Response, or { status, data }.
 */
(function () {
    'use strict';

    var GENERIC = 'Something went wrong. Please try again.';

    function readStatus(source) {
        if (!source) return 0;
        if (typeof source.status === 'number') return source.status;
        return 0;
    }

    function readPayload(source) {
        if (!source) return null;
        // jQuery jqXHR
        if (source.responseJSON) return source.responseJSON;
        // Plain object passed by fetch callers: { status, data }
        if (source.data && typeof source.data === 'object') return source.data;
        if (typeof source.responseText === 'string' && source.responseText) {
            try {
                return JSON.parse(source.responseText);
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    /**
     * First validation message from a Laravel 422 payload.
     */
    function firstValidationMessage(payload) {
        if (!payload || !payload.errors || typeof payload.errors !== 'object') return '';
        var keys = Object.keys(payload.errors);
        if (!keys.length) return '';
        var first = payload.errors[keys[0]];
        return Array.isArray(first) ? String(first[0] || '') : String(first || '');
    }

    /**
     * Resolve the message a user should see for a failed request.
     */
    function slbHttpMessage(source, fallback) {
        var status = readStatus(source);
        var payload = readPayload(source);
        var serverMessage = payload && (payload.message || payload.error);

        if (status === 419) {
            return 'Your session expired. Refresh the page and sign in again.';
        }

        if (status === 401) {
            return 'You are signed out. Please sign in again to continue.';
        }

        if (status === 403) {
            return serverMessage || 'You do not have permission to do that.';
        }

        if (status === 422) {
            return firstValidationMessage(payload) || serverMessage || 'Please check the highlighted fields and try again.';
        }

        if (status === 429) {
            return 'Too many attempts. Please wait a moment and try again.';
        }

        if (status === 404) {
            return serverMessage || 'That item no longer exists. Refresh the page and try again.';
        }

        if (status >= 500) {
            // Never surface a server-provided 5xx body: it may carry internals.
            return 'We hit a problem on our side. Please try again in a moment.';
        }

        if (status === 0) {
            return 'Network error. Check your connection and try again.';
        }

        return serverMessage || fallback || GENERIC;
    }

    /**
     * Resolve the message and surface it using whichever UI helper is loaded.
     *
     * @param {object} source
     * @param {object} [options] { fallback, title, silent }
     * @returns {string} the message shown
     */
    function slbHandleHttpError(source, options) {
        options = options || {};
        var message = slbHttpMessage(source, options.fallback);

        if (options.silent) return message;

        if (typeof window.showAppToast === 'function') {
            window.showAppToast(message, 'error');
        } else if (typeof window.slbAlert === 'function') {
            window.slbAlert(message, { title: options.title || 'Error', icon: 'error' });
        } else if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire(options.title || 'Error', message, 'error');
        } else {
            console.error(message);
        }

        return message;
    }

    window.slbHttpMessage = slbHttpMessage;
    window.slbHandleHttpError = slbHandleHttpError;
})();
