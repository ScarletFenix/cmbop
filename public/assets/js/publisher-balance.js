/**
 * Publisher Balance — move withdrawable earnings into the advertiser wallet.
 */
(function () {
    'use strict';

    var form = document.getElementById('roleMoveForm');
    if (!form) {
        return;
    }

    var amountInput = document.getElementById('roleMoveAmount');
    var moveBtn = document.getElementById('roleMoveBtn');
    var allBtn = document.getElementById('roleMoveAllBtn');
    var csrf = form.querySelector('input[name="_token"]');
    var url = form.getAttribute('data-url') || form.getAttribute('action');
    var min = parseFloat(form.getAttribute('data-min') || '0.01');
    var max = parseFloat(form.getAttribute('data-max') || '0');
    var canMove = form.getAttribute('data-can-move') === '1';
    var blockedReason = form.getAttribute('data-blocked-reason') || 'This amount cannot be moved.';
    var busy = false;

    function euro(n) {
        return '€' + Number(n).toFixed(2);
    }

    function parsedAmount() {
        var raw = amountInput ? String(amountInput.value || '').trim() : '';
        var value = parseFloat(raw);
        return Number.isFinite(value) ? Math.round(value * 100) / 100 : 0;
    }

    function toast(message, type) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(message, type || 'success');
            return;
        }
        if (typeof window.slbAlert === 'function') {
            window.slbAlert({ icon: type === 'error' ? 'error' : 'success', text: message });
        }
    }

    function setBusy(on) {
        busy = on;
        if (moveBtn && !moveBtn.disabled) {
            moveBtn.disabled = on;
        }
        if (allBtn) {
            allBtn.disabled = on;
        }
        if (amountInput && canMove) {
            amountInput.disabled = on;
        }
    }

    if (allBtn) {
        allBtn.addEventListener('click', function () {
            if (!amountInput || !canMove) {
                return;
            }
            amountInput.value = max.toFixed(2);
            amountInput.focus();
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (busy) {
            return;
        }
        if (!canMove) {
            toast(blockedReason, 'error');
            return;
        }

        var amount = parsedAmount();
        if (amount < min || amount > max) {
            toast(
                amount > max
                    ? 'Not enough withdrawable earnings for this amount. Bonus credit cannot be moved.'
                    : 'Enter an amount of at least ' + euro(min) + '.',
                'error'
            );
            return;
        }

        var confirmFn = window.slbConfirm;
        var proceed = typeof confirmFn === 'function'
            ? confirmFn({
                title: 'Use for spending?',
                text: 'Move ' + euro(amount) + ' of withdrawable earnings to your advertiser wallet? No fee. You can spend it on placements right after.',
                confirmText: 'Move',
                icon: 'question',
            })
            : Promise.resolve(true);

        proceed.then(function (ok) {
            if (!ok) {
                return;
            }
            setBusy(true);

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.value : (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ amount: amount }),
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { response: response, data: data };
                }).catch(function () {
                    return { response: response, data: null };
                });
            }).then(function (result) {
                if (result.response.ok && result.data && result.data.success) {
                    toast(result.data.message || 'Earnings moved to your advertiser wallet.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 400);
                    return;
                }
                setBusy(false);
                if (typeof window.slbHandleHttpError === 'function') {
                    window.slbHandleHttpError({
                        status: result.response.status,
                        data: result.data,
                    });
                    return;
                }
                toast((result.data && result.data.message) || 'Could not move earnings. Please try again.', 'error');
            }).catch(function (err) {
                setBusy(false);
                if (typeof window.slbHandleHttpError === 'function') {
                    window.slbHandleHttpError(err);
                    return;
                }
                toast('Could not move earnings. Please try again.', 'error');
            });
        });
    });
})();
