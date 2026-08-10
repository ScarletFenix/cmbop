/**
 * Confirm before switching active role (SweetAlert when available).
 */
(function () {
    function bindRoleSwitchForms(root) {
        (root || document).querySelectorAll('.role-switch-form').forEach(function (form) {
            if (form.dataset.slbRoleSwitchBound === '1') return;
            form.dataset.slbRoleSwitchBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('.role-switch-btn');
                var roleName = (btn && btn.dataset.roleName) || 'the other role';
                var proceed = function () {
                    // Native submit after confirm (avoid re-triggering this handler loop)
                    HTMLFormElement.prototype.submit.call(form);
                };

                // slbConfirm owns the SweetAlert / native fallback.
                window.slbConfirm({
                    title: 'Switch role?',
                    // text is what the native fallback shows; html is the richer dialog.
                    text: 'You are about to switch to ' + roleName + '. Your current page will change to that workspace.',
                    html: 'You are about to switch to <strong>' + roleName + '</strong>. Your current page will change to that workspace.',
                    confirmText: 'Switch to ' + roleName,
                    cancelText: 'Stay here',
                    icon: 'question',
                }).then(function (ok) {
                    if (ok) proceed();
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bindRoleSwitchForms(document); });
    } else {
        bindRoleSwitchForms(document);
    }

    window.slbBindRoleSwitchForms = bindRoleSwitchForms;
})();
