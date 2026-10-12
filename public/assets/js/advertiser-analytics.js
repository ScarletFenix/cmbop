(function () {
    function refreshThemeSelects() {
        document.querySelectorAll('[data-theme-select]').forEach(function (wrap) {
            var select = wrap.querySelector('select');
            var valueEl = wrap.querySelector('.single-select-value');
            if (!select || !valueEl) return;
            var val = String(select.value);
            wrap.querySelectorAll('.single-select-option').forEach(function (opt) {
                var on = String(opt.getAttribute('data-value')) === val;
                opt.classList.toggle('selected', on);
                opt.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            var selected = wrap.querySelector('.single-select-option.selected');
            valueEl.textContent = selected
                ? String(selected.getAttribute('data-label') || selected.textContent || '').trim()
                : String((select.options[select.selectedIndex] && select.options[select.selectedIndex].text) || '').trim();
        });
    }

    function bindThemeSelects() {
        document.querySelectorAll('[data-theme-select]').forEach(function (wrap) {
            if (wrap.dataset.bound === '1') return;
            wrap.dataset.bound = '1';
            var select = wrap.querySelector('select');
            var trigger = wrap.querySelector('.single-select-input');
            var dropdown = wrap.querySelector('.single-select-dropdown');
            if (!select || !trigger || !dropdown) return;

            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                var willOpen = !dropdown.classList.contains('show');
                document.querySelectorAll('.theme-select .single-select-dropdown.show').forEach(function (dd) {
                    if (dd === dropdown) return;
                    dd.classList.remove('show');
                    var other = dd.previousElementSibling;
                    if (other) other.setAttribute('aria-expanded', 'false');
                });
                dropdown.classList.toggle('show', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            wrap.querySelectorAll('.single-select-option').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    var next = String(opt.getAttribute('data-value') || '');
                    if (select.value !== next) {
                        select.value = next;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    refreshThemeSelects();
                    dropdown.classList.remove('show');
                    trigger.setAttribute('aria-expanded', 'false');
                });
            });
        });
        refreshThemeSelects();
    }

    function applyRangePreset(form, preset) {
        var from = document.getElementById('analyticsFrom');
        var to = document.getElementById('analyticsTo');
        if (!from || !to) return;
        if (preset === 'all') {
            from.value = '';
            to.value = '';
        } else if (preset === '30d') {
            from.value = form.getAttribute('data-last30') || '';
            to.value = form.getAttribute('data-today') || '';
        } else if (preset === 'month') {
            from.value = form.getAttribute('data-month-start') || '';
            to.value = form.getAttribute('data-today') || '';
        }
    }

    function submitForm(form) {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindThemeSelects();
        var form = document.getElementById('analyticsRangeForm');
        if (!form) return;

        var rangeSelect = document.getElementById('analyticsRangeFilter');
        if (rangeSelect) {
            rangeSelect.addEventListener('change', function () {
                applyRangePreset(form, String(rangeSelect.value));
                submitForm(form);
            });
        }

        ['analyticsViewFilter', 'analyticsBreakdownFilter'].forEach(function (id) {
            var select = document.getElementById(id);
            if (!select) return;
            select.addEventListener('change', function () {
                submitForm(form);
            });
        });

        ['analyticsFrom', 'analyticsTo'].forEach(function (id) {
            var input = document.getElementById(id);
            if (!input) return;
            input.addEventListener('change', function () {
                if (rangeSelect && rangeSelect.value !== 'custom') {
                    rangeSelect.value = 'custom';
                    refreshThemeSelects();
                }
            });
        });
    });
})();
