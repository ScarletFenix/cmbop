/**
 * Ensure Bootstrap modals paint above the sticky app shell (topbar/sidebar).
 * Reparents the modal to <body> before it is shown so page stacking contexts
 * cannot trap it under .top-navbar (z-index 1060 vs Bootstrap default 1055).
 */
(function () {
  'use strict';

  document.addEventListener('show.bs.modal', function (event) {
    var el = event.target;
    if (!el || !el.classList || !el.classList.contains('modal')) return;
    if (el.parentElement !== document.body) {
      document.body.appendChild(el);
    }
  }, true);
})();
