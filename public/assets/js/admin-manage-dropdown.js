/**
 * Keep admin Manage dropdowns from being clipped by .admin-table-fit overflow.
 * Used on Users, Sites, Payments, Withdrawals, and any future manage menus.
 */
(function () {
    'use strict';

    function syncManageOpenState() {
        document.querySelectorAll('.admin-table-fit').forEach(function (wrap) {
            var open = !!wrap.querySelector('.admin-manage-dropdown .dropdown-menu.show');
            wrap.classList.toggle('is-manage-open', open);
        });
    }

    document.addEventListener('show.bs.dropdown', function (e) {
        var dropdown = e.target && e.target.closest
            ? e.target.closest('.admin-manage-dropdown')
            : null;
        if (!dropdown) return;
        var fit = dropdown.closest('.admin-table-fit');
        if (fit) fit.classList.add('is-manage-open');
    });

    document.addEventListener('shown.bs.dropdown', function (e) {
        var dropdown = e.target && e.target.closest
            ? e.target.closest('.admin-manage-dropdown')
            : null;
        if (!dropdown) return;
        var menu = dropdown.querySelector('.dropdown-menu');
        if (menu) menu.scrollTop = 0;
        syncManageOpenState();
    });

    document.addEventListener('hidden.bs.dropdown', function (e) {
        if (!e.target || !e.target.closest || !e.target.closest('.admin-manage-dropdown')) {
            return;
        }
        syncManageOpenState();
    });
})();
