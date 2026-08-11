/* Advertiser Orders page — expects window.AdvertiserOrdersConfig */
(function () {
    if (!window.AdvertiserOrdersConfig) {
        window.AdvertiserOrdersConfig = { csrfToken: '', routes: {} };
    }
})();

const OrdersCfg = window.AdvertiserOrdersConfig;
function ordersRoute(key, fallback) {
    const routes = (OrdersCfg && OrdersCfg.routes) || {};
    return routes[key] || fallback || '';
}
function ordersCsrf() {
    return (OrdersCfg && OrdersCfg.csrfToken)
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '';
}
function ordersUrl(pathSuffix) {
    const base = String(ordersRoute('ordersBase', '')).replace(/\/$/, '');
    return base + pathSuffix;
}

let currentPage = 1;
let currentChatOrderId = null;

function loadOrdStatistics() {
    const url = ordersRoute('statistics');
    if (!url) return;
    fetch(url, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Failed to load statistics');
            }
            return response.json();
        })
        .then(function (response) {
            if (!response.success || !response.data) {
                return;
            }
            var data = response.data;
            var setText = function (id, value) {
                var el = document.getElementById(id);
                if (el) el.textContent = String(value ?? 0);
            };
            setText('ordNeedsReview', data.needs_review);
            setText('ordInProgress', data.in_progress);
            setText('ordCompleted', data.completed);
            setText('ordAwaitingPayment', data.awaiting_payment);
        })
        .catch(function (error) {
            console.error('Error loading order statistics:', error);
        });
}

function applyOrdersStatusFilter(status) {
    const sel = document.getElementById('statusFilter');
    if (!sel) return;
    sel.value = status || '';
    currentPage = 1;
    if (typeof window.fetchOrders === 'function') {
        window.fetchOrders(1, { historyMode: 'push' });
    }
    document.querySelectorAll('[data-orders-kpi]').forEach(function (btn) {
        btn.classList.toggle('is-active', (btn.getAttribute('data-orders-kpi') || '') === (status || ''));
    });
}
window.applyOrdersStatusFilter = applyOrdersStatusFilter;

const ORDERS_SEARCH_LIVE_MS = 350;
const ORDERS_SEARCH_MIN_CHARS = 2;
const ORDERS_FETCH_TIMEOUT_MS = 15000;
let ordersSearchTimer = null;
let ordersFetchController = null;
let ordersFetchTimeoutId = null;

function setOrdersSearchStatus(message) {
    const el = document.getElementById('ordersSearchStatus');
    if (el) el.textContent = message || '';
}

function setOrdersSearchBusy(busy) {
    const card = document.getElementById('ordersResultsCard');
    const badge = document.getElementById('ordersSearchBusy');
    const input = document.getElementById('searchInput');
    if (card) {
        card.classList.toggle('is-busy', !!busy);
        card.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    if (badge) badge.classList.toggle('d-none', !busy);
    if (input) input.setAttribute('aria-busy', busy ? 'true' : 'false');
    setOrdersSearchStatus(busy ? 'Searching…' : '');
}

function updateOrdersSearchClearVisibility() {
    const input = document.getElementById('searchInput');
    const clearBtn = document.getElementById('ordersSearchClear');
    if (!clearBtn) return;
    const has = !!(input && String(input.value || '').trim());
    clearBtn.classList.toggle('d-none', !has);
}

function runOrdersLiveFetch(page, options) {
    const opts = options || {};
    currentPage = page || 1;
    if (typeof window.fetchOrders === 'function') {
        window.fetchOrders(currentPage, opts);
        return true;
    }
    return false;
}

function scheduleOrdersLiveSearch(options) {
    const opts = options || {};
    if (ordersSearchTimer) {
        clearTimeout(ordersSearchTimer);
        ordersSearchTimer = null;
    }
    const run = function () {
        ordersSearchTimer = null;
        const q = String(document.getElementById('searchInput')?.value || '').trim();
        if (q.length > 0 && q.length < ORDERS_SEARCH_MIN_CHARS) {
            setOrdersSearchStatus('Type at least 2 characters to search');
            updateOrdersSearchClearVisibility();
            return;
        }
        if (!runOrdersLiveFetch(1, {
            historyMode: opts.historyMode || 'replace',
            intent: 'search',
        })) {
            setOrdersSearchStatus('Search is still loading…');
        }
    };
    if (opts.immediate) {
        run();
        return;
    }
    ordersSearchTimer = setTimeout(run, ORDERS_SEARCH_LIVE_MS);
}

function initOrdersLiveSearch() {
    const input = document.getElementById('searchInput');
    if (!input || input.dataset.ordersLiveBound === '1') return;
    input.dataset.ordersLiveBound = '1';

    if (typeof window.SlbLiveSearch === 'undefined' || typeof window.SlbLiveSearch.init !== 'function') {
        // Fallback if shared helper failed to load — still live-filter the table.
        input.addEventListener('input', function () {
            updateOrdersSearchClearVisibility();
            scheduleOrdersLiveSearch({ historyMode: 'replace' });
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                scheduleOrdersLiveSearch({ immediate: true, historyMode: 'push' });
            }
        });
        document.getElementById('ordersSearchClear')?.addEventListener('click', function () {
            input.value = '';
            updateOrdersSearchClearVisibility();
            runOrdersLiveFetch(1, { historyMode: 'push', intent: 'search' });
            input.focus();
        });
        updateOrdersSearchClearVisibility();
        return;
    }

    window.SlbLiveSearch.init(input, {
        mode: 'event',
        statusEl: document.getElementById('ordersSearchStatus'),
        clearBtn: document.getElementById('ordersSearchClear'),
        minChars: ORDERS_SEARCH_MIN_CHARS,
        debounceMs: ORDERS_SEARCH_LIVE_MS,
        onSearch: function (detail) {
            updateOrdersSearchClearVisibility();
            if (detail.reason === 'enter' || detail.reason === 'clear') {
                runOrdersLiveFetch(1, {
                    historyMode: 'push',
                    intent: 'search',
                });
                return;
            }
            // SlbLiveSearch already debounced — refresh the table immediately.
            scheduleOrdersLiveSearch({ immediate: true, historyMode: detail.historyMode || 'replace' });
        },
    });

    updateOrdersSearchClearVisibility();
}

function bootAdvertiserOrdersPage() {
    // Expose fetchOrders immediately (function declarations below are hoisted) so
    // live search / KPI clicks keep working even if OrderChat setup throws later.
    window.fetchOrders = fetchOrders;

    hydrateOrdersFiltersFromUrl();
    fetchOrders(currentPage, { historyMode: 'replace' });
    loadOrdStatistics();
    setInterval(function () {
        if (!document.hidden) loadOrdStatistics();
    }, 30000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) loadOrdStatistics();
    });

    document.querySelectorAll('[data-orders-kpi]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyOrdersStatusFilter(btn.getAttribute('data-orders-kpi') || '');
        });
    });

    window.addEventListener('popstate', function() {
        hydrateOrdersFiltersFromUrl();
        fetchOrders(currentPage, { syncUrl: false });
    });

    document.getElementById('resetFilters')?.addEventListener('click', function() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = '';
        document.getElementById('paymentStatusFilter').value = '';
        document.getElementById('paymentMethodFilter').value = '';
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        updateOrdersSearchClearVisibility();
        currentPage = 1;
        if (ordersSearchTimer) {
            clearTimeout(ordersSearchTimer);
            ordersSearchTimer = null;
        }
        fetchOrders(1, { historyMode: 'push' });
    });

    document.getElementById('showNeedsReviewBtn')?.addEventListener('click', function() {
        document.getElementById('statusFilter').value = 'review';
        currentPage = 1;
        fetchOrders(1, { historyMode: 'push' });
    });

    document.getElementById('filterForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        if (ordersSearchTimer) {
            clearTimeout(ordersSearchTimer);
            ordersSearchTimer = null;
        }
        fetchOrders(1, { historyMode: 'push' });
    });

    // Dropdown / date filters live-refresh the table (catalog-style), not only on Filter click.
    ['statusFilter', 'paymentStatusFilter', 'paymentMethodFilter', 'dateFrom', 'dateTo'].forEach(function (id) {
        document.getElementById(id)?.addEventListener('change', function () {
            currentPage = 1;
            fetchOrders(1, { historyMode: 'replace', intent: 'search' });
        });
    });

    function hydrateOrdersFiltersFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const setVal = (id, key) => {
            const el = document.getElementById(id);
            if (el && params.has(key)) el.value = params.get(key) || '';
        };
        setVal('searchInput', 'search');
        setVal('statusFilter', 'status');
        setVal('paymentStatusFilter', 'payment_status');
        setVal('paymentMethodFilter', 'payment_method');
        setVal('dateFrom', 'date_from');
        setVal('dateTo', 'date_to');
        // Clear fields that are no longer in the URL (browser back/forward)
        ['searchInput', 'statusFilter', 'paymentStatusFilter', 'paymentMethodFilter', 'dateFrom', 'dateTo'].forEach((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            const key = id === 'searchInput' ? 'search'
                : id === 'statusFilter' ? 'status'
                : id === 'paymentStatusFilter' ? 'payment_status'
                : id === 'paymentMethodFilter' ? 'payment_method'
                : id === 'dateFrom' ? 'date_from'
                : 'date_to';
            if (!params.has(key)) el.value = '';
        });
        const page = parseInt(params.get('page') || '1', 10);
        currentPage = Number.isFinite(page) && page > 0 ? page : 1;
        updateOrdersSearchClearVisibility();
    }
    window.hydrateOrdersFiltersFromUrl = hydrateOrdersFiltersFromUrl;

    function syncOrdersFiltersToUrl(page = 1, options = {}) {
        const url = new URL(window.location.href);
        const map = {
            search: document.getElementById('searchInput')?.value || '',
            status: document.getElementById('statusFilter')?.value || '',
            payment_status: document.getElementById('paymentStatusFilter')?.value || '',
            payment_method: document.getElementById('paymentMethodFilter')?.value || '',
            date_from: document.getElementById('dateFrom')?.value || '',
            date_to: document.getElementById('dateTo')?.value || '',
        };
        Object.keys(map).forEach((key) => {
            if (map[key]) url.searchParams.set(key, map[key]);
            else url.searchParams.delete(key);
        });
        if (page > 1) url.searchParams.set('page', String(page));
        else url.searchParams.delete('page');
        const mode = options.historyMode || 'push';
        if (mode === 'replace' && window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState({ ordersLive: 1 }, '', url);
        } else if (mode !== 'none') {
            window.history.pushState({ ordersLive: 1 }, '', url);
        }
    }
    window.syncOrdersFiltersToUrl = syncOrdersFiltersToUrl;

    initOrdersLiveSearch();

    function escapeHtml(str) {
        if (window.OrderChatEscapeHtml) {
            return window.OrderChatEscapeHtml(str);
        }
        if (str == null || str === '') return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function safeUrl(url) {
        const s = String(url || '').trim();
        if (!s) return '#';
        if (/^https?:\/\//i.test(s) || s.startsWith('/')) {
            return escapeHtml(s);
        }
        return '#';
    }

    /** JS string literal safe inside double-quoted HTML attributes (onclick="..."). */
    function jsAttr(value) {
        return JSON.stringify(String(value ?? '')).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    function hideOrderDetailsModal() {
        const el = document.getElementById('orderDetailsModal');
        if (!el || !window.bootstrap || !bootstrap.Modal) return;
        const instance = bootstrap.Modal.getInstance(el);
        if (instance) instance.hide();
    }

    function hideChatModal() {
        const el = document.getElementById('chatModal');
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            const instance = bootstrap.Modal.getInstance(el);
            if (instance) instance.hide();
            return;
        }
        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(el).modal('hide');
        }
    }

    function showBsModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).show();
            return;
        }
        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(el).modal('show');
        }
    }

    function hideBsModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            const instance = bootstrap.Modal.getInstance(el);
            if (instance) instance.hide();
            return;
        }
        if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
            window.jQuery(el).modal('hide');
        }
    }

    function renderChatOrderDetails(details) {
        const el = document.getElementById('chatOrderDetails');
        if (!el) return;
        if (!details) {
            el.classList.add('d-none');
            el.innerHTML = '';
            window._chatOrderId = null;
            return;
        }

        window._chatOrderId = details.order_id || window._chatOrderId || null;
        const websiteName = escapeHtml(details.website_name || '—');
        const websiteUrl = details.website_url
            ? `<a class="chat-od__url" href="${safeUrl(details.website_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(details.website_url)}</a>`
            : '';
        const statusLabel = escapeHtml(details.status_label || details.status || '—');
        const nextAction = escapeHtml(details.next_action || '');
        const autoHint = details.auto_approve_hint
            ? `<div class="chat-od__hint">${escapeHtml(details.auto_approve_hint)}</div>`
            : '';

        // Status summary only — review actions live in the View order details modal
        el.innerHTML = `
            <div class="chat-od">
                <div class="chat-od__site">
                    <span class="chat-detail-primary">${websiteName}</span>
                    ${websiteUrl}
                </div>
                <div class="chat-od__status">
                    <strong>${statusLabel}</strong>
                    ${nextAction ? `<span class="chat-od__next">${nextAction}</span>` : ''}
                </div>
                ${autoHint}
            </div>`;
        el.classList.remove('d-none');
    }

    var orderChat = null;
    if (typeof window.OrderChat === 'function') {
        try {
            orderChat = new window.OrderChat({
                baseUrl: window.location.origin,
                renderOrderDetails: renderChatOrderDetails,
                onFocusOrder: function(orderId) {
                    // viewOrder is assigned later in this file — retry until ready (bell / deep links).
                    var attempts = 0;
                    function tryOpen() {
                        if (typeof window.viewOrder === 'function') {
                            window.viewOrder(orderId);
                            return;
                        }
                        if (++attempts < 25) {
                            setTimeout(tryOpen, 200);
                        }
                    }
                    tryOpen();
                },
                onFocusMessagesFallback: function() {
                    const table = document.getElementById('ordersTableBody');
                    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
                },
                onClose: function() {
                    fetchOrders(currentPage);
                    if (typeof window.refreshHeaderAlerts === 'function') window.refreshHeaderAlerts();
                },
            });
            orderChat.init();
        } catch (err) {
            console.error('Order chat init failed:', err);
            orderChat = null;
        }
    }

    window.openChat = function(orderId, orderNumber) {
        hideOrderDetailsModal();
        currentChatOrderId = orderId;
        window._chatOrderId = orderId;
        if (orderChat) {
            orderChat.open(orderId, orderNumber);
        }
    };

    window.raiseIssue = function(orderId, orderNumber, statusLabel) {
        openChat(orderId, orderNumber || ('#' + orderId));
        const input = document.getElementById('chatMessageInput');
        if (input && !input.disabled) {
            const label = statusLabel || 'unknown';
            input.value = `I'd like to raise an issue with order #${orderNumber} (status: ${label}). Please help resolve this.`;
            setTimeout(() => input.focus(), 300);
        }
    };

    window.recheckLiveUrl = function(orderId) {
        const btn = document.getElementById('recheckLiveUrlBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Checking…';
        }
        fetch(ordersUrl(`/${orderId}/recheck-live-url`), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': ordersCsrf(),
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Checked', data.message || 'URL check finished.', data.live_url_check?.ok ? 'success' : 'warning');
                viewOrder(orderId);
            } else {
                Swal.fire('Error', data.message || 'Could not recheck URL.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Could not recheck URL.', 'error'))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-refresh me-1"></i>Recheck';
            }
        });
    };

    // Request modification
    window.requestModification = function(orderId) {
        document.getElementById('modificationOrderId').value = orderId;
        document.getElementById('modificationReason').value = '';
        showBsModal('modificationModal');
    };

    document.getElementById('confirmModification')?.addEventListener('click', function() {
        const orderId = document.getElementById('modificationOrderId').value;
        const reason = document.getElementById('modificationReason').value.trim();

        if (reason.length < 10) {
            Swal.fire('Warning!', 'Please provide at least 10 characters describing what needs to change.', 'warning');
            return;
        }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';

        fetch(ordersUrl(`/${orderId}/request-modification`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': ordersCsrf()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ reason: reason })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                hideBsModal('modificationModal');
                fetchOrders(currentPage);
            } else {
                Swal.fire('Error!', data.message || 'Failed to request modification', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error!', 'Failed to request modification', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = 'Send change request';
        });
    });

    // Publisher asked for a revised article — advertiser fulfills with a new content link
    window.fulfillContentRevision = function(orderId) {
        Swal.fire({
            title: 'Send revised article',
            html: `
                <p class="small text-muted text-start mb-2">Paste a link to the updated article (Google Doc, Dropbox, etc.).</p>
                <input id="swal-content-link" class="swal2-input" placeholder="https://…" style="width:90%">
                <textarea id="swal-content-note" class="swal2-textarea" placeholder="Optional note for the publisher"></textarea>
            `,
            showCancelButton: true,
            confirmButtonText: 'Send to publisher',
            focusConfirm: false,
            preConfirm: () => {
                const link = (document.getElementById('swal-content-link')?.value || '').trim();
                const note = (document.getElementById('swal-content-note')?.value || '').trim();
                if (!link) {
                    Swal.showValidationMessage('A content link is required');
                    return false;
                }
                try { new URL(link); } catch (e) {
                    Swal.showValidationMessage('Enter a valid URL');
                    return false;
                }
                return { content_link: link, note };
            }
        }).then((result) => {
            if (!result.isConfirmed || !result.value) return;
            fetch(ordersUrl(`/${orderId}/fulfill-content-revision`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': ordersCsrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(result.value),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Sent', data.message || 'Revised article sent.', 'success');
                    fetchOrders(currentPage);
                } else {
                    Swal.fire('Error!', data.message || 'Failed to send revised article', 'error');
                }
            })
            .catch(() => Swal.fire('Error!', 'Failed to send revised article', 'error'));
        });
    };

    function ordersHaveActiveFilters() {
        return !!(
            (document.getElementById('searchInput')?.value || '').trim()
            || document.getElementById('statusFilter')?.value
            || document.getElementById('paymentStatusFilter')?.value
            || document.getElementById('paymentMethodFilter')?.value
            || document.getElementById('dateFrom')?.value
            || document.getElementById('dateTo')?.value
        );
    }

    function updateResultsCount(pagination) {
        const el = document.getElementById('resultsCount');
        if (!el) return;
        if (!pagination || !pagination.total) {
            el.innerHTML = '';
            return;
        }
        const from = pagination.from || 0;
        const to = pagination.to || 0;
        const total = pagination.total || 0;
        el.textContent = total
            ? `Showing ${from}–${to} of ${total}`
            : '';
    }

    function fetchOrders(page = 1, options = {}) {
        const syncUrl = options.syncUrl !== false;
        const historyMode = options.historyMode || (syncUrl ? 'push' : 'none');
        const intent = options.intent || '';
        currentPage = page;
        const search = document.getElementById('searchInput')?.value || '';
        const status = document.getElementById('statusFilter')?.value || '';
        const paymentStatus = document.getElementById('paymentStatusFilter')?.value || '';
        const paymentMethod = document.getElementById('paymentMethodFilter')?.value || '';
        const dateFrom = document.getElementById('dateFrom')?.value || '';
        const dateTo = document.getElementById('dateTo')?.value || '';

        const listUrl = ordersRoute('list');
        if (!listUrl) {
            setOrdersSearchStatus('Orders list URL is missing');
            return;
        }

        let url = `${listUrl}?page=${page}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        if (status) url += `&status=${encodeURIComponent(status)}`;
        if (paymentStatus) url += `&payment_status=${encodeURIComponent(paymentStatus)}`;
        if (paymentMethod) url += `&payment_method=${encodeURIComponent(paymentMethod)}`;
        if (dateFrom) url += `&date_from=${encodeURIComponent(dateFrom)}`;
        if (dateTo) url += `&date_to=${encodeURIComponent(dateTo)}`;

        if (syncUrl && typeof window.syncOrdersFiltersToUrl === 'function') {
            window.syncOrdersFiltersToUrl(page, { historyMode: historyMode === 'none' ? 'push' : historyMode });
        }

        if (ordersFetchController) {
            try { ordersFetchController.abort(); } catch (err) { /* ignore */ }
        }
        if (ordersFetchTimeoutId) {
            clearTimeout(ordersFetchTimeoutId);
            ordersFetchTimeoutId = null;
        }
        ordersFetchController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const localController = ordersFetchController;
        if (localController) {
            ordersFetchTimeoutId = setTimeout(function () {
                try { localController.abort(); } catch (err) { /* ignore */ }
            }, ORDERS_FETCH_TIMEOUT_MS);
        }

        setOrdersSearchBusy(intent === 'search' || !!String(search).trim());
        updateOrdersSearchClearVisibility();

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': ordersCsrf(),
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: localController ? localController.signal : undefined,
        })
        .then(response => response.json().catch(() => ({})).then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (localController && ordersFetchController !== localController) return;
            if (ok && data.success) {
                renderOrders(data.orders, data.pagination);
                updateNeedsActionBanner(data.needs_action || 0);
                setOrdersSearchStatus(data.pagination && data.pagination.total
                    ? `${data.pagination.total} order${data.pagination.total === 1 ? '' : 's'} found`
                    : 'No orders found');
                return;
            }

            if (ok) {
                document.getElementById('ordersTableBody').innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <div class="text-muted">${escapeHtml(data.message || 'No orders found')}</div>
                        </td>
                    </tr>
                `;
                updateResultsCount(null);
                document.getElementById('paginationNav').innerHTML = '';
                updateNeedsActionBanner(0);
                setOrdersSearchStatus(data.message || 'No orders found');
                return;
            }

            throw new Error(data.message || 'Failed to load orders. Please try again.');
        })
        .catch(error => {
            if (error && error.name === 'AbortError') {
                return;
            }
            if (localController && ordersFetchController !== localController) return;
            console.error('Error:', error);
            document.getElementById('ordersTableBody').innerHTML = `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="text-danger mb-2">${escapeHtml(error.message || 'Failed to load orders. Please try again.')}</div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="retryOrdersBtn">Retry</button>
                    </td>
                </tr>
            `;
            updateResultsCount(null);
            document.getElementById('paginationNav').innerHTML = '';
            setOrdersSearchStatus('Search failed');
            document.getElementById('retryOrdersBtn')?.addEventListener('click', () => fetchOrders(currentPage));
        })
        .finally(() => {
            if (localController && ordersFetchController !== localController) return;
            if (ordersFetchTimeoutId) {
                clearTimeout(ordersFetchTimeoutId);
                ordersFetchTimeoutId = null;
            }
            setOrdersSearchBusy(false);
        });
    }
    window.fetchOrders = fetchOrders;

    function updateNeedsActionBanner(count) {
        const banner = document.getElementById('needsActionBanner');
        const text = document.getElementById('needsActionText');
        if (!banner || !text) return;
        if (count > 0) {
            text.textContent = `${count} order${count === 1 ? '' : 's'} have a live URL ready — approve or request changes.`;
            banner.classList.remove('d-none');
        } else {
            banner.classList.add('d-none');
        }
    }

    function getAdvertiserStatusMeta(order) {
        if (order.status_label && order.next_action) {
            return {
                label: order.status_label,
                next: order.next_action,
                cls: getStatusClass(order.status),
                autoHint: order.auto_approve_hint || null,
            };
        }

        const item = order.items && order.items[0] ? order.items[0] : null;
        const hasLiveUrl = !!(item && item.live_url);
        const modRequested = item && item.modification_requested === 'yes';
        const contentRevisionRequested = item && item.content_revision_requested === 'yes';
        const payment = order.payment_status;
        const status = order.status;
        let autoHint = null;
        if (status === 'review' && hasLiveUrl && !modRequested && item && typeof item.auto_approve_hours_remaining === 'number') {
            const hours = item.auto_approve_hours_remaining;
            autoHint = hours > 0
                ? (hours >= 24
                    ? `Auto-approves in about ${Math.ceil(hours / 24)} day(s) if you take no action`
                    : `Auto-approves in about ${hours} hour(s) if you take no action`)
                : 'Ready for auto-approve — approve now or request changes';
        }

        if (status === 'cancelled' && payment === 'refunded') {
            return { label: 'Cancelled · refunded', next: 'Refunded to your wallet (usually instant). No further action needed.', cls: 'status-cancelled', autoHint: null };
        }
        if (status === 'cancelled') {
            return { label: 'Cancelled', next: 'No further action needed.', cls: 'status-cancelled', autoHint: null };
        }
        if (payment === 'failed') {
            return { label: 'Payment failed', next: 'Pay again from Orders, or choose another payment method.', cls: 'status-cancelled', autoHint: null };
        }
        if (status === 'pending' && payment !== 'paid') {
            return { label: 'Awaiting payment', next: 'Complete payment so the publisher can start.', cls: 'status-pending', autoHint: null };
        }
        if (status === 'pending' && payment === 'paid') {
            return { label: 'Paid · waiting for publisher', next: 'Publisher will accept the order and start working.', cls: 'status-pending', autoHint: null };
        }
        if (status === 'processing' && contentRevisionRequested) {
            return { label: 'Publisher needs revised article', next: 'Upload or link an updated article so the publisher can continue.', cls: 'status-processing', autoHint: null };
        }
        if (status === 'processing' && modRequested) {
            return { label: 'Revision requested', next: 'Waiting on the publisher to update the post and resubmit the live URL.', cls: 'status-processing', autoHint: null };
        }
        if (status === 'processing') {
            const accepted = item && item.accepted_at;
            return {
                label: accepted ? 'Accepted · processing' : 'Processing',
                next: 'Publisher is preparing and publishing your content, then will send a live URL.',
                cls: 'status-processing',
                autoHint: null,
            };
        }
        if (status === 'review') {
            return {
                label: 'URL delivered · your review',
                next: hasLiveUrl ? 'Check the live URL, then approve or request changes.' : 'Waiting for live URL.',
                cls: 'status-review',
                autoHint,
            };
        }
        if (status === 'completed') {
            return { label: 'Completed', next: 'All done — the publisher has been paid for this placement.', cls: 'status-completed', autoHint: null };
        }
        return { label: capitalize(status), next: '', cls: getStatusClass(status), autoHint: null };
    }

    function buildAdvertiserTimeline(order) {
        const item = order.items && order.items[0] ? order.items[0] : {};
        const status = order.status;
        const paid = ['paid', 'completed', 'refunded'].includes(order.payment_status)
            || ['processing', 'review', 'completed'].includes(status);
        const acceptedOrLater = ['processing', 'review', 'completed'].includes(status) || !!item.accepted_at;
        const urlDelivered = status === 'review' || status === 'completed';
        const completed = status === 'completed';
        const modRequested = item.modification_requested === 'yes';

        if (status === 'cancelled' && order.payment_status === 'refunded') {
            return `<div class="alert alert-secondary py-2 small mb-2">Cancelled · refunded to your wallet (usually instant).</div>
                <h6>Activity Timeline</h6>
                <div id="orderActivityTimeline" class="order-view-timeline">
                    <div class="text-muted small">Loading activity…</div>
                </div>`;
        }
        if (status === 'cancelled') {
            return `<div class="alert alert-secondary py-2 small mb-2">This order was cancelled.</div>
                <h6>Activity Timeline</h6>
                <div id="orderActivityTimeline" class="order-view-timeline">
                    <div class="text-muted small">Loading activity…</div>
                </div>`;
        }

        const steps = [
            { label: 'Paid', done: paid, current: false },
            { label: 'Accepted', done: acceptedOrLater, current: false },
            { label: modRequested && status === 'processing' ? 'Revision' : 'Processing', done: urlDelivered || completed, current: false },
            { label: 'URL delivered', done: completed, current: false },
            { label: 'Completed', done: completed, current: false },
        ];

        if (status === 'pending' && !paid) {
            steps[0].current = true;
            steps[0].done = false;
        } else if (status === 'pending' && paid) {
            steps[1].current = true;
        } else if (status === 'processing' && modRequested) {
            steps[2].current = true;
            steps[2].done = false;
            steps[3].done = false;
        } else if (status === 'processing') {
            steps[2].current = true;
        } else if (status === 'review') {
            steps[3].current = true;
            steps[3].done = false;
        } else if (status === 'completed') {
            steps[4].current = true;
        }

        const statusSteps = `<div class="order-view-status-steps">${steps.map((step, i) => {
            const cls = step.done ? 'bg-success text-white' : (step.current ? 'bg-info text-white' : 'bg-light text-muted');
            const arrow = i < steps.length - 1 ? '<span class="ov-arrow">→</span>' : '';
            return `<span class="badge ${cls}">${i + 1}. ${step.label}</span>${arrow}`;
        }).join('')}</div>`;

        const meta = getAdvertiserStatusMeta(order);
        const hint = meta.autoHint
            ? `<div class="small text-muted mb-2"><i class="fa fa-clock-o me-1"></i>${escapeHtml(meta.autoHint)}</div>`
            : '';

        return `${statusSteps}${hint}
            <h6>Activity Timeline</h6>
            <div id="orderActivityTimeline" class="order-view-timeline">
                <div class="text-muted small">Loading activity…</div>
            </div>`;
    }

    function loadOrderActivityTimeline(orderId) {
        const container = document.getElementById('orderActivityTimeline');
        if (!container) return;
        fetch(`${ordersRoute('orderTimelineBase')}/${orderId}/timeline`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                container.innerHTML = '<div class="text-muted small">Unable to load activity.</div>';
                return;
            }
            if (window.renderOrderActivityTimeline) {
                window.renderOrderActivityTimeline(container, data.activities || []);
            } else {
                container.innerHTML = '<div class="text-muted small">No activity recorded yet.</div>';
            }
        })
        .catch(() => {
            container.innerHTML = '<div class="text-muted small">Unable to load activity.</div>';
        });
    }

    function renderOrders(orders, pagination) {
        if (!orders || orders.length === 0) {
            const filtered = ordersHaveActiveFilters();
            document.getElementById('ordersTableBody').innerHTML = filtered ? `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="mx-auto" style="max-width:420px">
                            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                                 style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#1a585e)"
                                 aria-hidden="true">
                                <i class="fa-solid fa-filter-circle-xmark"></i>
                            </div>
                            <h5 class="mb-2">No matching orders</h5>
                            <p class="text-muted mb-3">Try clearing filters or adjusting your search.</p>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="emptyResetFiltersBtn">
                                <i class="fa-solid fa-rotate-right me-1"></i> Reset filters
                            </button>
                        </div>
                    </td>
                </tr>
            ` : `
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="mx-auto" style="max-width:420px">
                            <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                                 style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#1a585e)"
                                 aria-hidden="true">
                                <i class="fa-solid fa-receipt"></i>
                            </div>
                            <h5 class="mb-2">No orders yet</h5>
                            <p class="text-muted mb-3">When you buy placements from the catalog, they’ll show up here with status tracking.</p>
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <a href="${ordersRoute('catalog')}" class="btn btn-primary btn-sm">
                                    <i class="fa fa-shopping-cart me-1"></i> Browse catalog
                                </a>
                                <a href="${ordersRoute('contentLibrary')}" class="btn btn-outline-secondary btn-sm">Content library</a>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
            if (filtered) {
                document.getElementById('emptyResetFiltersBtn')?.addEventListener('click', () => {
                    document.getElementById('resetFilters')?.click();
                });
            }
            updateResultsCount(null);
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }

        let html = '';
        orders.forEach(order => {
            const statusMeta = getAdvertiserStatusMeta(order);
            const items = Array.isArray(order.items) ? order.items : [];
            const firstItem = items[0] || null;
            const siteName = firstItem ? firstItem.site_name : 'N/A';
            const siteUrl = firstItem ? firstItem.site_url : '';
            const itemsCount = order.items_count || items.length || 0;
            const moreCount = Math.max(0, itemsCount - 1);
            const totalAmount = parseFloat(order.total_amount || 0);
            
            const paymentMethodName = getPaymentMethodName(order.payment_method);
            const paymentStatusClass = getPaymentStatusClass(order.payment_status);
            const unreadBadge = order.unread_chat > 0
                ? `<span class="chat-unread-dot">${order.unread_chat}</span>`
                : '';
            const siteUrlHtml = siteUrl
                ? `<div class="text-muted small"><a href="${safeUrl(siteUrl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(siteUrl)}</a></div>`
                : '';
            const moreHtml = moreCount > 0
                ? `<div class="small text-muted mt-1">+${moreCount} more</div>`
                : '';
            const disputeHtml = order.dispute_status
                ? `<div class="mt-1"><span class="badge text-bg-${order.dispute_status === 'upheld' ? 'danger' : (order.dispute_status === 'dismissed' ? 'secondary' : 'warning')}">Dispute: ${escapeHtml(order.dispute_status)}</span></div>`
                : '';
            
            html += `
                <tr>
                    <td class="fw-semibold">${escapeHtml(order.order_number)}</td>
                    <td>
                        <div class="fw-semibold">${escapeHtml(siteName)}</div>
                        ${siteUrlHtml}
                        ${moreHtml}
                    </td>
                    <td>${formatDate(order.created_at)}</td>
                    <td class="fw-semibold text-primary">€${totalAmount.toFixed(2)}</td>
                    <td>
                        <div class="small mb-1">${escapeHtml(paymentMethodName)}</div>
                        <span class="status-badge ${paymentStatusClass}">${capitalize(order.payment_status)}</span>
                    </td>
                    <td>
                        <span class="status-badge ${statusMeta.cls}">${statusMeta.label}</span>
                        <div class="next-step-hint">${escapeHtml(statusMeta.next)}</div>
                        ${statusMeta.autoHint ? `<div class="next-step-hint text-muted"><i class="fa fa-clock-o me-1"></i>${escapeHtml(statusMeta.autoHint)}</div>` : ''}
                        ${disputeHtml}
                    </td>
                    <td>
                        <div class="action-buttons d-flex align-items-center gap-2 flex-wrap">
                            ${order.can_retry_payment ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-primary action-btn d-flex align-items-center"
                                onclick="retryOrderPayment(${order.id})">
                                <i class="fa fa-credit-card me-1"></i>
                                <span>Pay again</span>
                            </button>` : ''}
                            <button 
                                class="btn btn-sm btn-outline-info action-btn d-flex align-items-center"
                                onclick="viewOrder(${order.id})">
                                <i class="fa fa-eye me-1"></i>
                                <span>View</span>
                            </button>
                            <button 
                                class="btn btn-sm btn-outline-success action-btn d-flex align-items-center"
                                onclick="openChat(${order.id}, ${jsAttr(order.order_number || '')})">
                                <i class="fa fa-comments me-1"></i>
                                <span>Chat</span>${unreadBadge}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        document.getElementById('ordersTableBody').innerHTML = html;
        updateResultsCount(pagination);
        renderPagination(pagination);
    }
    
    // Approve order function
    window.approveOrder = function(orderId) {
        // Bootstrap's focus trap on #orderDetailsModal steals focus/clicks from
        // SweetAlert when Approve is clicked inside the modal. Close it first.
        try {
            const details = document.getElementById('orderDetailsModal');
            if (details && window.bootstrap && bootstrap.Modal) {
                const inst = bootstrap.Modal.getInstance(details);
                if (inst) inst.hide();
            }
        } catch (e) { /* ignore */ }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            || ordersCsrf();

        Swal.fire({
            title: 'Approve Order',
            text: 'Are you sure you want to approve this order? The publisher has submitted the live URL.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Approve',
            cancelButtonText: 'Cancel',
            // Keep focus inside Swal even if another overlay briefly re-opens.
            returnFocus: false,
            heightAuto: false,
        }).then((result) => {
            // SweetAlert2 v11+: isConfirmed. Guard older shapes just in case.
            if (!(result && (result.isConfirmed || result.value === true))) {
                return;
            }
            Swal.fire({
                title: 'Approving…',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading(),
            });

            fetch(ordersUrl(`/${orderId}/approve`), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
            .then(async (response) => {
                let data = null;
                try {
                    data = await response.json();
                } catch (e) {
                    if (response.status === 419) {
                        throw new Error('Session expired. Refresh the page and try again.');
                    }
                    throw new Error('Invalid response from server (' + response.status + ')');
                }
                if (!response.ok && !(data && data.message)) {
                    throw new Error(
                        response.status === 419
                            ? 'Session expired. Refresh the page and try again.'
                            : 'Request failed (' + response.status + ')'
                    );
                }
                return data;
            })
            .then(data => {
                if (data && data.success) {
                    fetchOrders(currentPage);
                    if (data.ask_rating && Array.isArray(data.rateable) && data.rateable.length) {
                        askPublisherRatings(data.rateable, data.message || 'Order approved successfully!');
                    } else {
                        Swal.fire('Approved!', data.message || 'Order approved successfully!', 'success');
                    }
                } else {
                    Swal.fire('Error!', (data && data.message) || 'Failed to approve order', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', error.message || 'Failed to approve order', 'error');
            });
        });
    };

    window.reportLinkRemoved = function(orderId) {
        Swal.fire({
            title: 'Report link removed',
            html: '<p class="small text-start mb-2">Use this if the publisher deleted the article after completion. Our team will review and may refund you while clawing back the publisher payout.</p>',
            input: 'textarea',
            inputLabel: 'What happened? (10–1000 characters)',
            inputPlaceholder: 'The live URL returns 404 / the article was deleted on …',
            inputAttributes: { maxlength: 1000 },
            showCancelButton: true,
            confirmButtonText: 'Submit dispute',
            customClass: { confirmButton: 'slb-swal-danger' },
            inputValidator: (value) => {
                const t = (value || '').trim();
                if (t.length < 10) return 'Please provide at least 10 characters.';
                if (t.length > 1000) return 'Please keep the reason under 1000 characters.';
                return null;
            }
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(ordersUrl(`/${orderId}/report-link-removed`), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': ordersCsrf(),
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ reason: result.value })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetchOrders(currentPage);
                    Swal.fire('Submitted', data.message, 'success');
                } else {
                    Swal.fire('Error', data.message || 'Failed to submit dispute', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Failed to submit dispute', 'error'));
        });
    };

    function starButtonsHtml(prefix) {
        let html = `<div class="d-flex justify-content-center gap-1 mb-2" id="${prefix}-stars">`;
        for (let i = 1; i <= 5; i++) {
            html += `<button type="button" class="btn btn-link p-0 rate-star-btn" data-value="${i}" style="font-size:28px;color:#cbd5e1;line-height:1;">
                <i class="fa-regular fa-star"></i>
            </button>`;
        }
        html += `</div><div class="small text-muted mb-2" id="${prefix}-label">Tap a star to rate</div>`;
        return html;
    }

    function bindStarPicker(prefix, state) {
        const wrap = document.getElementById(prefix + '-stars');
        const label = document.getElementById(prefix + '-label');
        if (!wrap) return;
        wrap.querySelectorAll('.rate-star-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                state.rating = parseInt(btn.dataset.value, 10);
                wrap.querySelectorAll('.rate-star-btn').forEach(b => {
                    const on = parseInt(b.dataset.value, 10) <= state.rating;
                    b.style.color = on ? '#f59e0b' : '#cbd5e1';
                    const icon = b.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-solid', on);
                        icon.classList.toggle('fa-regular', !on);
                    }
                });
                if (label) label.textContent = state.rating + ' / 5';
            });
        });
    }

    async function askPublisherRatings(rateable, approvedMessage) {
        const ratingsPayload = [];
        for (let idx = 0; idx < rateable.length; idx++) {
            const item = rateable[idx];
            const prefix = 'rate-' + item.order_item_id;
            const state = { rating: 0 };
            const result = await Swal.fire({
                title: 'Rate this publisher',
                html: `
                    <p class="mb-1">${approvedMessage && idx === 0 ? `<span class="text-success">${escapeHtml(approvedMessage)}</span><br>` : ''}
                    How was your experience with <strong>${escapeHtml(item.site_name || 'this site')}</strong>?</p>
                    <p class="small text-muted mb-3">${escapeHtml(item.domain || '')}</p>
                    ${starButtonsHtml(prefix)}
                    <input id="${prefix}-comment" class="swal2-input" placeholder="Optional short feedback" maxlength="500">
                    <p class="small text-muted mt-2 mb-0">Ratings are only available after you approve a completed order.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Submit rating',
                cancelButtonText: idx < rateable.length - 1 ? 'Skip' : 'Maybe later',
                didOpen: () => bindStarPicker(prefix, state),
                preConfirm: () => {
                    if (!state.rating) {
                        Swal.showValidationMessage('Please choose a star rating');
                        return false;
                    }
                    return {
                        order_item_id: item.order_item_id,
                        rating: state.rating,
                        comment: document.getElementById(prefix + '-comment')?.value || '',
                    };
                }
            });
            if (result.isConfirmed && result.value) {
                ratingsPayload.push(result.value);
            }
        }

        if (!ratingsPayload.length) {
            Swal.fire({icon:'success', title:'Approved!', text: approvedMessage, timer: 2200, showConfirmButton:false});
            return;
        }

        try {
            const res = await fetch(ordersRoute('ratingsBatch'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': ordersCsrf(),
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ratings: ratingsPayload })
            });
            const data = await res.json();
            Swal.fire({
                icon: data.success ? 'success' : 'error',
                title: data.success ? 'Thank you!' : 'Could not save rating',
                text: data.message || '',
            });
        } catch (e) {
            Swal.fire('Error', 'Failed to save rating', 'error');
        }
    }
    
    window.retryOrderPayment = function(orderId) {
        Swal.fire({
            title: 'Pay again?',
            text: 'We will open a new secure card checkout for this failed payment.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Continue to payment',
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }
            Swal.fire({
                title: 'Starting checkout…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });
            fetch(ordersUrl(`/${orderId}/retry-payment`), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': ordersCsrf(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.success && data.checkout_url) {
                        window.location.href = data.checkout_url;
                        return;
                    }
                    Swal.fire('Unable to retry', data.message || 'Please try again from checkout.', 'error');
                })
                .catch(() => {
                    Swal.fire('Error', 'Failed to start payment retry.', 'error');
                });
        });
    };

    window.viewOrder = function(orderId) {
        hideChatModal();
        fetch(ordersUrl(`/${orderId}`), {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': ordersCsrf(),
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderOrderDetails(data.order);
                const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('orderDetailsModal'));
                modal.show();
                loadOrderActivityTimeline(orderId);
            } else {
                Swal.fire('Error', data.message || 'Failed to load order details', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load order details', 'error');
        });
    }

    function liveUrlHealthBadge(item) {
        if (item.live_url_check_ok === true) {
            return '<span class="badge bg-success">Reachable</span>';
        }
        if (item.live_url_check_ok === false) {
            return '<span class="badge bg-warning text-dark">Unreachable / unverified</span>';
        }
        return '<span class="badge bg-secondary">Not checked yet</span>';
    }

    function renderOrderDetails(order) {
        const items = Array.isArray(order.items) ? order.items : [];
        const isUnderReview = order.status === 'review';
        const hasAnyLiveUrl = items.some((it) => it.live_url && it.live_url !== '');
        const statusMeta = getAdvertiserStatusMeta(order);
        const timelineHtml = buildAdvertiserTimeline(order);
        const itemsCount = order.items_count || items.length || 0;

        const pricingRows = items.map((it, idx) => {
            const additionalPrice = parseFloat(it.additional_price || 0);
            const linePrice = parseFloat(it.price || 0);
            const basePrice = Math.max(0, linePrice - additionalPrice);
            const label = itemsCount > 1 ? `Item ${idx + 1} · ${escapeHtml(it.site_name || 'Site')}` : 'Base';
            let rows = `<div class="ov-row"><strong>${label}</strong><span>€${basePrice.toFixed(2)}</span></div>`;
            if (additionalPrice > 0) {
                rows += `<div class="ov-row"><strong>Sensitive</strong><span class="text-warning">+ €${additionalPrice.toFixed(2)} (${escapeHtml(it.sensitive_type || 'Extra')})</span></div>`;
            }
            return rows;
        }).join('');

        const placementsHtml = items.map((it, idx) => {
            const liveUrl = it.live_url || null;
            const modRequested = it.modification_requested === 'yes';
            let healthHtml = '';
            if (liveUrl) {
                const checked = it.live_url_checked_at
                    ? ` · checked ${formatDate(it.live_url_checked_at)}`
                    : '';
                const http = it.live_url_http_status ? ` · HTTP ${it.live_url_http_status}` : '';
                healthHtml = `
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                        ${liveUrlHealthBadge(it)}
                        <span class="small text-muted">Public reachability check${http}${checked}</span>
                        ${idx === 0 ? `<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="recheckLiveUrlBtn" onclick="recheckLiveUrl(${order.id})">
                            <i class="fa fa-refresh me-1"></i>Recheck
                        </button>` : ''}
                    </div>`;
            }
            const liveUrlHtml = liveUrl
                ? `<div class="ov-block">
                        <strong>Live URL</strong>
                        <div><a href="${safeUrl(liveUrl)}" target="_blank" rel="noopener noreferrer" class="live-url">${escapeHtml(liveUrl)} <i class="fa fa-external-link fa-xs"></i></a></div>
                        ${healthHtml}
                   </div>`
                : `<div class="ov-block"><strong>Live URL</strong><div class="text-muted">Not submitted yet</div></div>`;
            const revisionHtml = modRequested && it.completion_notes
                ? `<div class="ui-callout ui-callout--attention ui-callout--sm ui-callout--flush mb-2"><span class="ui-callout__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span><div class="ui-callout__body"><strong>Change request:</strong> ${escapeHtml(it.completion_notes)}</div></div>`
                : '';
            const heading = itemsCount > 1
                ? `<h6 class="mt-2 mb-2">Placement ${idx + 1}${it.site_name ? ` · ${escapeHtml(it.site_name)}` : ''}</h6>`
                : '<h6>Placement</h6>';

            return `
                ${idx > 0 ? '<hr class="my-2">' : ''}
                ${heading}
                ${revisionHtml}
                <div class="ov-block">
                    <strong>Site</strong>
                    <div>${escapeHtml(it.site_name || '—')}</div>
                </div>
                <div class="ov-block">
                    <strong>Site URL</strong>
                    <div>${it.site_url ? `<a href="${safeUrl(it.site_url)}" target="_blank" rel="noopener noreferrer" class="text-primary">${escapeHtml(it.site_url)} <i class="fa fa-external-link fa-xs"></i></a>` : '—'}</div>
                </div>
                <div class="ov-block">
                    <strong>Document</strong>
                    <div>${it.content_link ? `<a href="${safeUrl(it.content_link)}" class="text-primary" target="_blank" rel="noopener noreferrer"><i class="fa fa-download me-1"></i>${escapeHtml(it.content_original_name || 'Download article')}</a>` : '—'}</div>
                    ${it.content_revision_requested === 'yes' ? `<div class="alert alert-warning py-2 small mt-2 mb-0">Publisher asked for a revised article${it.content_revision_reason ? ': ' + escapeHtml(it.content_revision_reason) : '.'}</div>` : ''}
                </div>
                <div class="ov-block">
                    <strong>Anchor text</strong>
                    <div>${escapeHtml(it.anchor_text || '—')}</div>
                </div>
                <div class="ov-block">
                    <strong>Target URL</strong>
                    <div>${it.target_url ? `<a href="${safeUrl(it.target_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(it.target_url)}</a>` : '—'}</div>
                </div>
                <div class="ov-block">
                    <strong>Feature image</strong>
                    <div>${it.feature_image_url ? `<a href="${safeUrl(it.feature_image_url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(it.feature_image_url)}</a>` : 'Publisher may choose'}</div>
                </div>
                <div class="ov-block">
                    <strong>Compliance</strong>
                    <div>${escapeHtml(it.moderation_status || '—')}</div>
                </div>
                ${liveUrlHtml}
            `;
        }).join('') || '<div class="text-muted">No placements on this order.</div>';

        let actionButtons = '';
        const needsContentRevision = order.items && order.items.some(it => it.content_revision_requested === 'yes');
        if (order.can_retry_payment) {
            actionButtons = `
                <button class="btn btn-sm btn-primary" onclick="retryOrderPayment(${order.id})">
                    <i class="fa fa-credit-card"></i> Pay again
                </button>
            `;
        } else if (needsContentRevision && order.status === 'processing') {
            actionButtons = `
                <button class="btn btn-sm btn-warning" onclick="fulfillContentRevision(${order.id})">
                    <i class="fa fa-upload"></i> Send revised article
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="openChat(${order.id}, ${jsAttr(order.order_number || '')})">
                    <i class="fa fa-comments"></i> Chat
                </button>
            `;
        } else if (isUnderReview && hasAnyLiveUrl) {
            actionButtons = `
                <button class="btn btn-sm btn-success" onclick="approveOrder(${order.id})">
                    <i class="fa fa-check-circle"></i> Approve
                </button>
                <button class="btn btn-sm btn-warning" onclick="requestModification(${order.id})">
                    <i class="fa fa-edit"></i> Request changes
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="raiseIssue(${order.id}, ${jsAttr(order.order_number || '')}, ${jsAttr(statusMeta.label || '')})">
                    <i class="fa fa-flag"></i> Raise an issue
                </button>
            `;
        } else if (order.status === 'completed') {
            actionButtons = `
                <button class="btn btn-sm btn-outline-secondary" onclick="openChat(${order.id}, ${jsAttr(order.order_number || '')})">
                    <i class="fa fa-comments"></i> Chat
                </button>
                ${order.can_report_link_removed ? `<button class="btn btn-sm btn-outline-danger" onclick="reportLinkRemoved(${order.id})">
                    <i class="fa fa-flag"></i> Report link removed
                </button>` : ''}
                ${order.dispute_status ? `<span class="badge text-bg-${order.dispute_status === 'upheld' ? 'danger' : (order.dispute_status === 'dismissed' ? 'secondary' : 'warning')}">Dispute: ${escapeHtml(order.dispute_status)}</span>` : ''}
            `;
        } else if (!['completed', 'cancelled'].includes(order.status) || order.payment_status === 'refunded') {
            actionButtons = `
                <button class="btn btn-sm btn-outline-secondary" onclick="openChat(${order.id}, ${jsAttr(order.order_number || '')})">
                    <i class="fa fa-comments"></i> Chat
                </button>
                ${order.status !== 'completed' ? `<button class="btn btn-sm btn-outline-danger" onclick="raiseIssue(${order.id}, ${jsAttr(order.order_number || '')}, ${jsAttr(statusMeta.label || '')})">
                    <i class="fa fa-flag"></i> Raise an issue
                </button>` : ''}
            `;
        }

        const html = `
            <div class="order-view-shell">
                <div class="order-view-panel">
                    <h6>Order details</h6>
                    <div class="ov-row"><strong>Order #</strong><span>${escapeHtml(order.order_number)}</span></div>
                    <div class="ov-row"><strong>Date</strong><span>${formatDate(order.created_at)}</span></div>
                    <div class="ov-row"><strong>Payment</strong><span>${escapeHtml(getPaymentMethodName(order.payment_method))}</span></div>
                    <div class="ov-row"><strong>Pay status</strong><span class="status-badge ${getPaymentStatusClass(order.payment_status)}">${capitalize(order.payment_status)}</span></div>
                    <div class="ov-row"><strong>Reference</strong><span>${escapeHtml(order.reference_code || '-')}</span></div>
                    ${itemsCount > 1 ? `<div class="ov-row"><strong>Items</strong><span>${itemsCount}</span></div>` : ''}
                    <hr class="my-2">
                    <h6>Status</h6>
                    <div class="ov-row"><strong>Now</strong><span class="status-badge ${statusMeta.cls}">${escapeHtml(statusMeta.label)}</span></div>
                    <p class="small text-muted mb-1">${escapeHtml(statusMeta.next)}</p>
                    ${statusMeta.autoHint ? `<p class="small text-muted mb-1"><i class="fa fa-clock-o me-1"></i>${escapeHtml(statusMeta.autoHint)}</p>` : ''}
                    <hr class="my-2">
                    ${pricingRows}
                    <div class="ov-row"><strong>Total</strong><span class="fw-bold text-primary">€${parseFloat(order.total_amount).toFixed(2)}</span></div>
                    <div class="order-view-refund">
                        Declines refund automatically · request changes before auto-approve ·
                        <a href="${ordersRoute('refundPolicy')}" target="_blank" rel="noopener">Refund policy</a>
                    </div>
                </div>

                <div class="order-view-panel order-view-panel--scroll">
                    ${placementsHtml}
                </div>

                <div class="order-view-panel">
                    <h6>Tracking</h6>
                    ${timelineHtml}
                </div>
            </div>
        `;

        document.getElementById('orderDetailsContent').innerHTML = html;
        const actionsEl = document.getElementById('orderDetailsActions');
        if (actionsEl) {
            actionsEl.innerHTML = actionButtons;
        }
    }

    function paginationPageWindow(current, last, radius = 2) {
        const pages = [];
        if (last <= 1) return pages;
        const start = Math.max(1, current - radius);
        const end = Math.min(last, current + radius);
        if (start > 1) {
            pages.push(1);
            if (start > 2) pages.push('ellipsis-start');
        }
        for (let i = start; i <= end; i++) pages.push(i);
        if (end < last) {
            if (end < last - 1) pages.push('ellipsis-end');
            pages.push(last);
        }
        return pages;
    }

    function renderPagination(pagination) {
        if (!pagination || pagination.last_page <= 1) {
            document.getElementById('paginationNav').innerHTML = '';
            return;
        }
        
        let paginationHtml = '<ul class="pagination justify-content-center flex-wrap">';
        
        if (pagination.current_page > 1) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page - 1}">Previous</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Previous</span></li>`;
        }

        paginationPageWindow(pagination.current_page, pagination.last_page, 2).forEach((entry) => {
            if (typeof entry === 'string') {
                paginationHtml += `<li class="page-item disabled"><span class="page-link">…</span></li>`;
                return;
            }
            if (entry === pagination.current_page) {
                paginationHtml += `<li class="page-item active"><span class="page-link">${entry}</span></li>`;
            } else {
                paginationHtml += `<li class="page-item"><button class="page-link" data-page="${entry}">${entry}</button></li>`;
            }
        });
        
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += `<li class="page-item"><button class="page-link" data-page="${pagination.current_page + 1}">Next</button></li>`;
        } else {
            paginationHtml += `<li class="page-item disabled"><span class="page-link">Next</span></li>`;
        }
        
        paginationHtml += '</ul>';
        document.getElementById('paginationNav').innerHTML = paginationHtml;
        
        document.querySelectorAll('.page-link[data-page]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.dataset.page);
                currentPage = page;
                fetchOrders(page);
            });
        });
    }

    function getStatusClass(status) {
        const classes = {
            'pending': 'status-pending',
            'processing': 'status-processing',
            'review': 'status-review',
            'completed': 'status-completed',
            'cancelled': 'status-cancelled'
        };
        return classes[status] || 'status-pending';
    }

    function getPaymentStatusClass(status) {
        const classes = {
            'paid': 'payment-paid',
            'pending': 'payment-pending',
            'failed': 'payment-failed',
            'refunded': 'payment-refunded'
        };
        return classes[status] || 'payment-pending';
    }

    function getPaymentMethodName(method) {
        const methods = {
            'wallet': 'Wallet Balance',
            'wise': 'Wise Transfer',
            'crypto': 'Cryptocurrency',
            'bank': 'Bank Transfer',
            'card': 'Card Payment'
        };
        return methods[method] || method;
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    function escapeHtml(str) {
        if (str == null || str === '') return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootAdvertiserOrdersPage);
} else {
    bootAdvertiserOrdersPage();
}
