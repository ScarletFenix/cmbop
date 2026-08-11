/**
 * Catalog main-search contract for every text search bar sitewide.
 *
 * Flow (matches advertiser catalog #catalogSearchInput):
 * - Typing: debounce 350ms, history replace
 * - Empty query: run (clear → full list)
 * - Non-empty below 2 chars: wait (optional status hint)
 * - Enter: cancel timer, run immediately, history push
 * - Clear control: empty + immediate push
 *
 * Modes via data-slb-live-search (auto-init) or SlbLiveSearch.init():
 * - "ajax" / "event" — call onSearch / dispatch slb:livesearch
 * - "form" — GET navigate from the closest form (replace on type, assign on Enter)
 * - "client" — same as event (DOM filters listen for slb:livesearch)
 */
(function (global) {
    'use strict';

    var DEBOUNCE_MS = 350;
    var MIN_CHARS = 2;
    var MIN_HINT = 'Type at least 2 characters to search';

    function trimQuery(input) {
        return String(input && input.value != null ? input.value : '').trim();
    }

    function resolveEl(ref, root) {
        if (!ref) return null;
        if (typeof ref !== 'string') return ref;
        if (ref.charAt(0) === '#') {
            return document.getElementById(ref.slice(1));
        }
        var byId = document.getElementById(ref);
        if (byId) return byId;
        try {
            return (root || document).querySelector(ref);
        } catch (err) {
            return null;
        }
    }

    function setStatus(statusEl, message) {
        if (statusEl) statusEl.textContent = message || '';
    }

    function updateClearVisibility(input, clearBtn) {
        if (!clearBtn) return;
        var has = trimQuery(input).length > 0;
        clearBtn.classList.toggle('d-none', !has);
        clearBtn.hidden = !has;
    }

    function navigateForm(input, historyMode) {
        var form = input.form || input.closest('form');
        if (!form) return;

        var action = form.getAttribute('action') || window.location.pathname;
        var url = new URL(action, window.location.origin);
        var fd = new FormData(form);
        var next = new URLSearchParams();

        fd.forEach(function (value, key) {
            var v = String(value == null ? '' : value).trim();
            if (v !== '') next.append(key, String(value));
        });

        url.search = next.toString();
        var href = url.pathname + url.search + url.hash;

        if (historyMode === 'replace') {
            window.location.replace(href);
        } else {
            window.location.assign(href);
        }
    }

    function callHandler(name, detail) {
        if (!name) return;
        var fn = global[name];
        if (typeof fn === 'function') {
            fn(detail);
        }
    }

    /**
     * @param {HTMLInputElement} input
     * @param {object} [options]
     */
    function init(input, options) {
        if (!input || input.nodeType !== 1) return null;
        if (input.dataset.slbLiveBound === '1') return input._slbLiveSearch || null;

        var opts = options || {};
        var mode = String(opts.mode || input.getAttribute('data-slb-live-search') || 'event').toLowerCase();
        if (mode === 'ajax') mode = 'event';

        var statusEl = resolveEl(opts.statusEl || opts.statusSelector || input.getAttribute('data-slb-live-status'), input.parentElement);
        if (!statusEl && input.getAttribute('aria-describedby')) {
            statusEl = document.getElementById(input.getAttribute('aria-describedby'));
        }

        var clearBtn = resolveEl(opts.clearBtn || opts.clearSelector || input.getAttribute('data-slb-live-clear'), input.parentElement);
        var handlerName = opts.handler || input.getAttribute('data-slb-live-handler') || '';
        var debounceMs = Number(opts.debounceMs != null ? opts.debounceMs : DEBOUNCE_MS);
        var minChars = Number(opts.minChars != null ? opts.minChars : MIN_CHARS);
        var minHint = opts.minCharsMessage || MIN_HINT;
        var timer = null;

        function clearTimer() {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        }

        function emit(detail) {
            var payload = Object.assign({
                query: trimQuery(input),
                reason: 'input',
                historyMode: 'replace',
                immediate: false,
                input: input,
            }, detail || {});

            if (typeof opts.onSearch === 'function') {
                opts.onSearch(payload);
            }

            callHandler(handlerName, payload);

            try {
                input.dispatchEvent(new CustomEvent('slb:livesearch', {
                    bubbles: true,
                    detail: payload,
                }));
            } catch (err) {
                // IE-free app; ignore.
            }

            if (mode === 'form') {
                navigateForm(input, payload.historyMode);
            }

            return payload;
        }

        function run(detail) {
            var q = trimQuery(input);
            updateClearVisibility(input, clearBtn);

            if (q.length > 0 && q.length < minChars) {
                clearTimer();
                setStatus(statusEl, minHint);
                return;
            }

            setStatus(statusEl, '');
            emit(detail);
        }

        function schedule(detail) {
            clearTimer();
            var immediate = !!(detail && detail.immediate);
            if (immediate) {
                run(detail);
                return;
            }
            timer = setTimeout(function () {
                timer = null;
                run(detail);
            }, debounceMs);
        }

        input.addEventListener('input', function () {
            updateClearVisibility(input, clearBtn);
            schedule({ reason: 'input', historyMode: 'replace', immediate: false });
        });

        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            clearTimer();
            run({ reason: 'enter', historyMode: 'push', immediate: true });
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                updateClearVisibility(input, clearBtn);
                clearTimer();
                setStatus(statusEl, '');
                run({ reason: 'clear', historyMode: 'push', immediate: true });
                input.focus();
            });
        }

        // Normalize chrome once.
        if (input.type !== 'search') {
            try { input.type = 'search'; } catch (err) { /* keep text */ }
        }
        if (!input.getAttribute('enterkeyhint')) {
            input.setAttribute('enterkeyhint', 'search');
        }
        if (!input.getAttribute('autocomplete')) {
            input.setAttribute('autocomplete', 'off');
        }

        input.dataset.slbLiveBound = '1';
        updateClearVisibility(input, clearBtn);

        var api = {
            input: input,
            schedule: schedule,
            run: run,
            clearTimer: clearTimer,
            refreshClear: function () { updateClearVisibility(input, clearBtn); },
        };
        input._slbLiveSearch = api;
        return api;
    }

    function autoInit(root) {
        var scope = root || document;
        var nodes = scope.querySelectorAll('[data-slb-live-search]');
        for (var i = 0; i < nodes.length; i++) {
            init(nodes[i]);
        }
    }

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        autoInit(document);
    });

    global.SlbLiveSearch = {
        DEBOUNCE_MS: DEBOUNCE_MS,
        MIN_CHARS: MIN_CHARS,
        MIN_HINT: MIN_HINT,
        init: init,
        autoInit: autoInit,
    };
})(window);
