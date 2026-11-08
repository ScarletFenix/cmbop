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

    document.addEventListener('DOMContentLoaded', function () {
        bindThemeSelects();
        var form = document.getElementById('advertiserBillingFilters');
        if (!form) return;

        form.querySelectorAll('[data-theme-select] select').forEach(function (select) {
            select.addEventListener('change', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    });
})();
