/* Catalog page JS — expects window.CatalogConfig */
(function () {
if (!window.CatalogConfig) { window.CatalogConfig = { favorites: [], blacklist: [], routes: {}, csrfToken: '' }; }
})();

document.addEventListener('DOMContentLoaded', function () {
    const filtersPanel = document.getElementById('catalogFiltersPanel');
    const filtersToggle = document.getElementById('toggleCatalogFilters');
    const filtersToggleLabel = document.getElementById('toggleCatalogFiltersLabel');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            const currentlyOpen = !filtersPanel.classList.contains('d-none');
            filtersPanel.classList.toggle('d-none', currentlyOpen);
            filtersToggle.setAttribute('aria-expanded', currentlyOpen ? 'false' : 'true');
            if (filtersToggleLabel) {
                filtersToggleLabel.textContent = currentlyOpen ? 'Show filters' : 'Hide filters';
            }
        });
    }

    const btn = document.getElementById('toggleMoreFiltersBtn');
    const drawer = document.getElementById('moreFiltersDrawer');
    if (btn && drawer) {
        btn.addEventListener('click', function () {
            const open = drawer.style.display !== 'none';
            drawer.style.display = open ? 'none' : 'block';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
    }

    // FR2 — preset chips set min/max inputs
    document.querySelectorAll('.filter-preset').forEach(function (chip) {
        chip.addEventListener('click', function () {
            const minEl = document.getElementById(chip.dataset.targetMin);
            const maxEl = document.getElementById(chip.dataset.targetMax);
            if (!minEl || !maxEl) return;
            minEl.value = chip.dataset.min || '';
            maxEl.value = chip.dataset.max || '';
            const group = chip.closest('.filter-presets');
            if (group) {
                group.querySelectorAll('.filter-preset').forEach(c => c.classList.remove('is-active'));
            }
            chip.classList.add('is-active');
        });
    });
});

// Initialize favorites and blacklist from database
const revealUrlEndpoint = (window.CatalogConfig && CatalogConfig.routes && CatalogConfig.routes.revealUrl) || '';
let favorites = (window.CatalogConfig && CatalogConfig.favorites) ? CatalogConfig.favorites.slice() : [];
let blacklist = (window.CatalogConfig && CatalogConfig.blacklist) ? CatalogConfig.blacklist.slice() : [];

// Multi-select variables
let selectedMultiFilters = {
    category: [],
    country: [],
    language: []
};

// Initialize from URL parameters
if (CatalogConfig.categoryParam) {
    selectedMultiFilters.category = String(CatalogConfig.categoryParam).split(',').filter(function(v) { return v; });
}
if (CatalogConfig.countryParam) {
    selectedMultiFilters.country = String(CatalogConfig.countryParam).split(',').filter(function(v) { return v; });
}
if (CatalogConfig.languageParam) {
    selectedMultiFilters.language = String(CatalogConfig.languageParam).split(',').filter(function(v) { return v; });
}

function closeAllMultiDropdowns(exceptId) {
    var dropdowns = document.querySelectorAll('.multi-select-dropdown');
    for (var i = 0; i < dropdowns.length; i++) {
        if (exceptId && dropdowns[i].id === exceptId) continue;
        dropdowns[i].classList.remove('show');
        var otherTrigger = dropdowns[i].previousElementSibling;
        if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
    }
}

function getVisibleMultiOptions(dropdown) {
    return Array.prototype.slice.call(dropdown.querySelectorAll('.option-item')).filter(function (el) {
        return el.style.display !== 'none';
    });
}

function focusMultiOption(dropdown, index) {
    var options = getVisibleMultiOptions(dropdown);
    if (!options.length) return;
    var i = ((index % options.length) + options.length) % options.length;
    options.forEach(function (el) { el.classList.remove('is-keyboard-focus'); });
    options[i].classList.add('is-keyboard-focus');
    var input = options[i].querySelector('input');
    if (input) input.focus({ preventScroll: false });
    options[i].scrollIntoView({ block: 'nearest' });
    dropdown.dataset.focusIndex = String(i);
}

function toggleMultiDropdown(dropdownId, triggerEl) {
    if (typeof event !== 'undefined' && event) event.stopPropagation();
    closeAllMultiDropdowns(dropdownId);
    var dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    var willOpen = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willOpen);
    if (triggerEl) triggerEl.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) {
        var searchInput = dropdown.querySelector('.search-box input');
        if (searchInput) {
            searchInput.value = '';
            var list = dropdown.querySelector('.options-list');
            if (list) filterMultiOptions(list.id, '');
            setTimeout(function () { searchInput.focus(); }, 10);
        }
        dropdown.dataset.focusIndex = '-1';
    }
}

document.addEventListener('keydown', function (e) {
    var openDropdown = document.querySelector('.multi-select-dropdown.show');
    var trigger = e.target.closest && e.target.closest('.multi-select-input');

    if (trigger && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
        e.preventDefault();
        var wrapper = trigger.closest('.multi-select-wrapper');
        var dropdown = wrapper ? wrapper.querySelector('.multi-select-dropdown') : null;
        if (dropdown) toggleMultiDropdown(dropdown.id, trigger);
        return;
    }

    if (!openDropdown) return;

    if (e.key === 'Escape') {
        e.preventDefault();
        openDropdown.classList.remove('show');
        var openTrigger = openDropdown.previousElementSibling;
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.focus();
        }
        return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var current = parseInt(openDropdown.dataset.focusIndex || '-1', 10);
        focusMultiOption(openDropdown, e.key === 'ArrowDown' ? current + 1 : current - 1);
        return;
    }

    if (e.key === 'Enter' && e.target && e.target.matches && e.target.matches('.option-item input, .option-item')) {
        // native checkbox toggle via Enter on focused input
        return;
    }
});

function filterMultiOptions(optionsId, searchTerm) {
    var options = document.getElementById(optionsId);
    if (!options) return;
    var optionItems = options.querySelectorAll('.option-item');
    var term = (searchTerm || '').toLowerCase().trim();
    var visible = 0;

    for (var i = 0; i < optionItems.length; i++) {
        var option = optionItems[i];
        var text = (option.querySelector('span') ? option.querySelector('span').textContent : '').toLowerCase();
        var code = (option.querySelector('input') ? option.querySelector('input').value : '').toLowerCase();
        var match = term === '' || text.indexOf(term) !== -1 || code.indexOf(term) !== -1;
        option.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    }

    var empty = options.parentElement ? options.parentElement.querySelector('.multi-select-empty') : null;
    if (empty) empty.classList.toggle('d-none', visible > 0);
}

function updateMultiFilter(checkbox) {
    var type = checkbox.getAttribute('data-type');
    var value = checkbox.value;
    
    if (checkbox.checked) {
        if (selectedMultiFilters[type].indexOf(value) === -1) {
            selectedMultiFilters[type].push(value);
        }
    } else {
        var newArray = [];
        for (var i = 0; i < selectedMultiFilters[type].length; i++) {
            if (selectedMultiFilters[type][i] !== value) {
                newArray.push(selectedMultiFilters[type][i]);
            }
        }
        selectedMultiFilters[type] = newArray;
    }
    
    // Update display
    updateMultiDisplay(type);
}

function updateMultiDisplay(type) {
    var container = document.getElementById('selected' + type.charAt(0).toUpperCase() + type.slice(1) + 'sDisplay');
    var values = selectedMultiFilters[type];
    
    if (!container) return;
    
    container.innerHTML = '';
    
    if (values.length === 0) {
        container.innerHTML = '<span class="placeholder-text">Select ' + type + 's...</span>';
        return;
    }
    
    for (var i = 0; i < values.length; i++) {
        var value = values[i];
        var displayName = value;
        
        if (type === 'country') {
            var option = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
        if (type === 'language') {
            var option = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
            if (option) {
                displayName = option.getAttribute('data-name') || value;
            }
        }
        
        /* Built with DOM nodes rather than an HTML string: these labels come from
           the database, and the old inline onclick put them inside a quoted JS
           argument, so a single apostrophe broke the handler. */
        var tag = document.createElement('span');
        tag.className = 'selected-tag';
        tag.appendChild(document.createTextNode(displayName + ' '));

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'remove-tag';
        remove.dataset.filterType = type;
        remove.dataset.filterValue = value;
        remove.setAttribute('aria-label', 'Remove filter ' + displayName);
        remove.innerHTML = '&times;';
        tag.appendChild(remove);

        container.appendChild(tag);
    }
}

/* One delegated listener for every filter tag, however often they re-render. */
document.addEventListener('click', function (e) {
    var remove = e.target.closest ? e.target.closest('.remove-tag[data-filter-type]') : null;
    if (!remove) return;

    e.preventDefault();
    e.stopPropagation();
    removeMultiFilter(remove.dataset.filterType, remove.dataset.filterValue);
});

function removeMultiFilter(type, value) {
    var newArray = [];
    for (var i = 0; i < selectedMultiFilters[type].length; i++) {
        if (selectedMultiFilters[type][i] !== value) {
            newArray.push(selectedMultiFilters[type][i]);
        }
    }
    selectedMultiFilters[type] = newArray;
    
    var checkbox = document.querySelector('#' + type + 'MultiOptions input[value="' + value + '"]');
    if (checkbox) {
        checkbox.checked = false;
    }
    
    updateMultiDisplay(type);
}

function initializeMultiSelects() {
    // Initialize checkboxes
    for (var i = 0; i < selectedMultiFilters.category.length; i++) {
        var value = selectedMultiFilters.category[i];
        var checkbox = document.querySelector('#categoryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.country.length; i++) {
        var value = selectedMultiFilters.country[i];
        var checkbox = document.querySelector('#countryMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    for (var i = 0; i < selectedMultiFilters.language.length; i++) {
        var value = selectedMultiFilters.language[i];
        var checkbox = document.querySelector('#languageMultiOptions input[value="' + value + '"]');
        if (checkbox) checkbox.checked = true;
    }
    
    // Update displays
    updateMultiDisplay('category');
    updateMultiDisplay('country');
    updateMultiDisplay('language');
}

function submitCatalogFilters() {
    document.getElementById('selectedCategory').value = selectedMultiFilters.category.join(',');
    document.getElementById('selectedCountry').value = selectedMultiFilters.country.join(',');
    document.getElementById('selectedLanguage').value = selectedMultiFilters.language.join(',');
    document.getElementById('filterForm').submit();
}

// Apply Filters button - submit the form with all selected values
(function () {
    const applyBtn = document.getElementById('applyFiltersBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            submitCatalogFilters();
        });
    }
})();

// Favorites / Blacklist selects apply immediately so heart & block workflows are obvious
['favorites_filter', 'blacklist_filter'].forEach(function (name) {
    const select = document.querySelector('select[name="' + name + '"]');
    if (!select) return;
    select.addEventListener('change', function () {
        submitCatalogFilters();
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.multi-select-wrapper')) {
        var dropdowns = document.querySelectorAll('.multi-select-dropdown');
        for (var i = 0; i < dropdowns.length; i++) {
            if (dropdowns[i]) {
                dropdowns[i].classList.remove('show');
            }
        }
    }
});

// Initialize multi-selects on page load
initializeMultiSelects();

/**
 * Escape a value before it goes into markup.
 *
 * Category names, country labels and publisher-defined sensitive-topic keys all
 * reach this file as plain strings and several places build HTML from them.
 */
function catalogEscapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Prefer shared layout toast (partials/app-toast); keep a local fallback for catalog-only pages.
function catalogToast(message, type = 'success', options) {
    if (typeof window.showAppToast === 'function') {
        return window.showAppToast(message, type, options);
    }
    if (typeof window.showToast === 'function' && window.showToast !== catalogToast) {
        return window.showToast(message, type, options);
    }
    if (typeof window.slbAlert === 'function') {
        window.slbAlert({ icon: type === 'error' ? 'error' : 'success', title: message });
        return;
    }
    console.warn(message);
}

// Cart mutations live on window.addToCart from the advertiser layout.
// Do not declare a top-level function addToCart / updateCartBadge — classic
// scripts hoist those onto window and recurse until the Buy button crashes.

/**
 * Read the checked sensitive-topic radio for a catalog site.
 * DOM is the source of truth (avoids stale in-memory maps after re-renders).
 */
function getSelectedSensitiveForSite(siteId) {
    const id = String(siteId);
    // One radio name is shared across desktop expand + mobile card.
    const checked = document.querySelector(
        'input.sensitive-price-checkbox[name="sensitive_prices_' + id + '"]:checked'
    );
    if (!checked) {
        return { type: null, additionalPrice: 0, totalPrice: null, basePrice: null };
    }

    const group = checked.closest('.sensitive-prices-group');
    const basePrice = parseFloat(group && group.dataset.basePrice != null
        ? group.dataset.basePrice
        : (checked.dataset.basePrice || '0')) || 0;
    const type = (checked.dataset.type || '').trim();
    const additionalPrice = parseFloat(checked.dataset.additionalPrice);
    const totalFromData = parseFloat(checked.dataset.totalPrice);
    const addOn = Number.isFinite(additionalPrice) ? additionalPrice : 0;

    if (!type || type === 'none' || !(addOn > 0)) {
        return {
            type: null,
            additionalPrice: 0,
            totalPrice: Number.isFinite(totalFromData) ? totalFromData : basePrice,
            basePrice: basePrice,
        };
    }

    return {
        type: type,
        additionalPrice: addOn,
        totalPrice: Number.isFinite(totalFromData) ? totalFromData : (basePrice + addOn),
        basePrice: basePrice,
    };
}

// Update UI for favorites and blacklist (quiet icon actions)
function updateButtonStates() {
    document.querySelectorAll('.favorite-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        const icon = btn.querySelector('i');
        if (favorites.includes(id)) {
            btn.classList.add('is-active');
            if (icon) { icon.classList.remove('fa-regular'); icon.classList.add('fa-solid'); }
            btn.title = 'Remove from Favorites';
            btn.setAttribute('aria-label', 'Remove from favorites');
        } else {
            btn.classList.remove('is-active');
            if (icon) { icon.classList.remove('fa-solid'); icon.classList.add('fa-regular'); }
            btn.title = 'Add to Favorites';
            btn.setAttribute('aria-label', 'Add to favorites');
        }
    });

    document.querySelectorAll('.blacklist-btn').forEach(btn => {
        let id = parseInt(btn.dataset.id);
        if (blacklist.includes(id)) {
            btn.classList.add('is-active');
            btn.title = 'Remove from Blacklist';
            btn.setAttribute('aria-label', 'Remove from blacklist');
        } else {
            btn.classList.remove('is-active');
            btn.title = 'Blacklist Site';
            btn.setAttribute('aria-label', 'Blacklist site');
        }
        btn.style.backgroundColor = '';
        btn.style.color = '';
    });
}

// Update buy button price display (desktop table + mobile cards share data-id).
function updateBuyButtonPrice(siteId, basePrice, additionalPrice = 0, sensitiveType = null) {
    const id = String(siteId);
    const base = parseFloat(basePrice);
    const addOn = parseFloat(additionalPrice);
    const safeBase = Number.isFinite(base) ? base : 0;
    const safeAdd = Number.isFinite(addOn) && addOn > 0 ? addOn : 0;
    const totalPrice = safeBase + safeAdd;

    document.querySelectorAll('.buy-now[data-id="' + id + '"]').forEach(function (buyButton) {
        const priceSpan = buyButton.querySelector('.base-price-display')
            || buyButton.querySelector('.fw-semibold');
        if (priceSpan) {
            priceSpan.textContent = '€' + totalPrice.toFixed(2);
        }

        // Keep strike-through list price visible; mark when an add-on is active.
        buyButton.dataset.currentAdditionalPrice = String(safeAdd);
        if (sensitiveType) {
            buyButton.dataset.sensitiveType = sensitiveType;
            buyButton.setAttribute('aria-label',
                'Buy placement' + (buyButton.dataset.name ? ' for ' + buyButton.dataset.name : '')
                + ' with ' + sensitiveType + ' add-on, €' + totalPrice.toFixed(2));
        } else {
            delete buyButton.dataset.sensitiveType;
            if (buyButton.dataset.name) {
                buyButton.setAttribute('aria-label', 'Buy placement for ' + buyButton.dataset.name);
            }
        }
    });
}

function syncSensitiveSelectionUi(siteId) {
    const selected = getSelectedSensitiveForSite(siteId);
    const basePrice = selected.basePrice != null
        ? selected.basePrice
        : (parseFloat((document.querySelector(
            '.sensitive-prices-group[data-site-id="' + String(siteId) + '"]'
        ) || {}).dataset?.basePrice) || 0);

    updateBuyButtonPrice(siteId, basePrice, selected.additionalPrice, selected.type);

    let infoHtml;
    if (selected.type && selected.additionalPrice > 0) {
        const total = selected.totalPrice != null
            ? selected.totalPrice
            : (basePrice + selected.additionalPrice);
        infoHtml =
            '<small class="text-muted">Base price: <strong>€' + basePrice.toFixed(2) + '</strong></small><br>'
            + '<small class="text-success">Selected: <strong>' + catalogEscapeHtml(selected.type)
            + '</strong> — Total: <strong>€' + Number(total).toFixed(2)
            + '</strong> (+€' + selected.additionalPrice.toFixed(2) + ')</small>';
    } else {
        infoHtml =
            '<small class="text-muted">Current price: <strong>€' + basePrice.toFixed(2)
            + '</strong> (Base price)</small>';
    }

    ['price-info-' + siteId, 'price-info-mobile-' + siteId].forEach(function (infoId) {
        const priceInfoDiv = document.getElementById(infoId);
        if (priceInfoDiv) {
            priceInfoDiv.innerHTML = infoHtml;
        }
    });
}

/**
 * Save favourites and report whether it stuck.
 *
 * The heart flips before the request finishes, so a failed save used to leave
 * the site looking saved when it was not. Resolves false on failure so the
 * caller can put the previous state back.
 */
function saveFavorites() {
    return fetch(CatalogConfig.routes.favoritesSave, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken
        },
        body: JSON.stringify({ favorites: favorites })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save favorites');
        }
        return true;
    }).catch(err => {
        console.error('Error saving favorites:', err);
        catalogToast(err.message || 'Could not save favorites', 'error');
        return false;
    });
}

// Save blacklist to database
/** Same contract as saveFavorites: false means the change did not persist. */
function saveBlacklist() {
    return fetch(CatalogConfig.routes.blacklistSave, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken
        },
        body: JSON.stringify({ blacklist: blacklist })
    }).then(async (res) => {
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            throw new Error(data.message || data.error || 'Could not save blacklist');
        }
        return true;
    }).catch(err => {
        console.error('Error saving blacklist:', err);
        catalogToast(err.message || 'Could not save blacklist', 'error');
        return false;
    });
}

function hideCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.transition = 'opacity 0.3s ease';
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, 300);
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.transition = 'opacity 0.3s ease';
        expandedRow.style.opacity = '0';
        setTimeout(() => { expandedRow.style.display = 'none'; }, 300);
    }
}

function showCatalogSite(siteId) {
    document.querySelectorAll(`.site-row[data-id="${siteId}"], .catalog-mobile-card[data-id="${siteId}"]`).forEach((el) => {
        el.style.display = '';
        el.style.opacity = '';
        el.style.transition = '';
        el.classList.remove('blacklisted-row', 'is-blacklisted');
    });
    const expandedRow = document.querySelector('.expanded-row-' + siteId);
    if (expandedRow) {
        expandedRow.style.display = '';
        expandedRow.style.opacity = '';
        expandedRow.style.transition = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateButtonStates();

    // Sensitive topic radios: delegate so late/expanded markup still works.
    document.addEventListener('change', function (e) {
        const radio = e.target && e.target.closest
            ? e.target.closest('.sensitive-price-checkbox')
            : null;
        if (!radio || !radio.checked) return;

        e.stopPropagation();

        const siteId = radio.dataset.siteId
            || (radio.closest('.sensitive-prices-group') || {}).dataset?.siteId;
        if (!siteId) return;

        syncSensitiveSelectionUi(siteId);

        const selected = getSelectedSensitiveForSite(siteId);
        if (selected.type && selected.additionalPrice > 0) {
            const total = selected.totalPrice != null
                ? selected.totalPrice
                : (selected.basePrice + selected.additionalPrice);
            catalogToast(
                selected.type + ' selected: +€' + selected.additionalPrice.toFixed(2)
                + ' — Total: €' + Number(total).toFixed(2),
                'success'
            );
        }
    });

    /**
     * Ask the server for one publisher domain.
     *
     * The masked host is all the page was sent, so this is a real request rather
     * than a CSS toggle — which is what makes the disclosure loggable. There is
     * no quota: browse as long as you like. If the server asks us to wait it is
     * because the pace looks automated, so we simply wait and try again, which a
     * person barely notices.
     */
    async function requestReveal(button, attempt) {
        attempt = attempt || 1;

        const siteId = button.dataset.siteId;
        const suffix = button.dataset.targetSuffix ? button.dataset.targetSuffix + '-' : '';
        const hostEl = document.getElementById('url-host-' + suffix + siteId);
        if (!hostEl) return;

        const icon = button.querySelector('i');
        button.dataset.busy = '1';
        if (icon) icon.className = 'fa-solid fa-spinner fa-spin';

        const restore = () => {
            if (icon) icon.className = 'fa-regular fa-eye';
            button.dataset.busy = '';
        };

        try {
            const res = await fetch(revealUrlEndpoint.replace('__SITE__', siteId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
            });
            const json = await res.json();

            if (json.code === 'slow_down') {
                const wait = Math.max(1, Number(json.retry_after) || 3);

                // The server quotes the real time until there is room, so a short
                // wait is worth absorbing silently — the reader sees a spinner and
                // then their address. Only retry once: a second refusal means the
                // pace is sustained, and spinning for minutes is worse than saying so.
                if (wait <= 10 && attempt === 1) {
                    await new Promise(r => setTimeout(r, wait * 1000));
                    return requestReveal(button, attempt + 1);
                }

                restore();
                if (window.showAppToast) {
                    window.showAppToast(json.message, 'warning');
                } else if (window.Swal) {
                    Swal.fire({ icon: 'info', title: 'Going a little fast', text: json.message });
                }
                return;
            }

            if (json.code === 'paused') {
                restore();
                if (window.Swal) {
                    // Same Swal chrome as before; pre-line keeps the three-part
                    // pause copy readable without inventing a new dialog.
                    const body = String(json.message || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    Swal.fire({
                        icon: 'info',
                        title: 'Paused for a moment',
                        html: `<div style="white-space:pre-line;text-align:left">${body}</div>`,
                    });
                } else if (window.showAppToast) {
                    window.showAppToast(json.message, 'warning');
                }
                return;
            }

            if (!json.success) {
                throw new Error(json.message || 'Could not open that address');
            }

            hostEl.textContent = json.url;
            hostEl.dataset.host = json.url;
            hostEl.removeAttribute('data-glass-tip');
            hostEl.removeAttribute('data-glass-tip-title');
            hostEl.removeAttribute('data-glass-tip-body');

            button.classList.add('d-none');
            button.dataset.busy = '';
            if (icon) icon.className = 'fa-regular fa-eye';

            const hideBtn = document.getElementById('url-hide-' + siteId);
            if (hideBtn) hideBtn.classList.remove('d-none');
        } catch (err) {
            restore();
            if (window.showAppToast) {
                window.showAppToast(err.message || 'Could not open that address', 'error');
            }
        }
    }

    function catalogActionClick(e) {
        // Any control in the row (eye, open, chevron, buy, chips…) must not
        // also toggle the details panel via the row click handler.
        return !!e.target.closest(
            'button, a, input, label, select, textarea, .reveal-url, .hide-url, .toggle-url, .expand-arrow, .btn-icon-quiet, .site-open-link, .buy-now, .favorite-btn, .blacklist-btn, .btn-claim-site, .copy-example-url, .sensitive-price-checkbox, .form-check-label, .site-chip, .site-badge-new'
        );
    }

    function revealButtonFor(siteId, preferSuffix) {
        if (preferSuffix) {
            return document.getElementById('url-reveal-' + preferSuffix + '-' + siteId)
                || document.getElementById('url-reveal-' + siteId);
        }
        return document.getElementById('url-reveal-' + siteId)
            || document.getElementById('url-reveal-mobile-' + siteId);
    }

    function hostElementFor(siteId, suffix) {
        const prefix = suffix ? suffix + '-' : '';
        return document.getElementById('url-host-' + prefix + siteId)
            || document.getElementById('url-host-' + siteId)
            || document.getElementById('url-host-mobile-' + siteId);
    }

    // Capture phase so reveal wins over the bubbling row-expand handler.
    document.addEventListener('click', function (e) {
        const button = e.target.closest('.reveal-url, .toggle-url');
        if (!button) return;

        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }

        if (button.dataset.busy === '1') return;

        const siteId = button.dataset.siteId || button.dataset.id;
        if (!siteId) return;

        const suffix = button.dataset.targetSuffix
            || (button.dataset.urlPrefix === 'mobile' ? 'mobile' : '');
        const hostEl = hostElementFor(siteId, suffix);
        const revealBtn = button.classList.contains('reveal-url')
            ? button
            : (revealButtonFor(siteId, suffix) || button);

        // Already disclosed and merely hidden for screen-sharing: put it back
        // without asking the server for anything.
        if (hostEl && hostEl.dataset.host) {
            hostEl.textContent = hostEl.dataset.host;
            if (revealBtn) revealBtn.classList.add('d-none');
            const hideBtn = document.getElementById('url-hide-' + siteId);
            if (hideBtn) hideBtn.classList.remove('d-none');
            return;
        }

        if (revealBtn) {
            if (suffix && !revealBtn.dataset.targetSuffix) {
                revealBtn.dataset.targetSuffix = suffix;
            }
            requestReveal(revealBtn, 1);
        }
    }, true);

    // Cosmetic hide: for screen-sharing. Costs nothing and asks nothing.
    document.addEventListener('click', function (e) {
        const button = e.target.closest('.hide-url');
        if (!button) return;

        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }

        const siteId = button.dataset.siteId;
        const hostEl = hostElementFor(siteId, '');
        if (!hostEl) return;

        if (!hostEl.dataset.host) hostEl.dataset.host = hostEl.textContent.trim();
        hostEl.textContent = '•••••••';

        button.classList.add('d-none');
        const revealBtn = revealButtonFor(siteId, '');
        if (revealBtn) revealBtn.classList.remove('d-none');
    }, true);

    // Toggle expanded row
    function toggleExpandRow(id, arrowElement) {
        let expandedRow = document.querySelector('.expanded-row-' + id);
        if (!expandedRow) return;
        
        if (expandedRow.style.display === 'none' || expandedRow.style.display === '') {
            document.querySelectorAll('[class^="expanded-row-"]').forEach(row => {
                if (row.style.display === 'table-row') {
                    row.style.display = 'none';
                    let rowId = row.className.match(/expanded-row-(\d+)/);
                    if (rowId && rowId[1]) {
                        let otherArrow = document.getElementById('arrow-' + rowId[1]);
                        if (otherArrow) {
                            otherArrow.classList.remove('rotate-arrow');
                            otherArrow.setAttribute('aria-expanded', 'false');
                        }
                    }
                }
            });
            
            expandedRow.style.display = 'table-row';

            // Load deferred expand screenshots on first open
            expandedRow.querySelectorAll('img.catalog-deferred-preview[data-src]').forEach(function (img) {
                if (!img.getAttribute('src')) {
                    img.setAttribute('src', img.getAttribute('data-src'));
                    img.removeAttribute('data-src');
                }
            });
            if (arrowElement) {
                arrowElement.classList.add('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'true');
            }
        } else {
            expandedRow.style.display = 'none';
            if (arrowElement) {
                arrowElement.classList.remove('rotate-arrow');
                arrowElement.setAttribute('aria-expanded', 'false');
            }
        }
    }

    document.querySelectorAll('.site-row').forEach(row => {
        row.addEventListener('click', function(e) {
            if (catalogActionClick(e)) {
                return;
            }
            
            let id = this.dataset.id;
            let arrowElement = document.getElementById('arrow-' + id);
            toggleExpandRow(id, arrowElement);
        });
    });

    document.querySelectorAll('.expand-arrow').forEach(arrow => {
        arrow.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = this.id.replace('arrow-', '');
            toggleExpandRow(id, this);
        });
    });

    // Copy example URL
    document.querySelectorAll('.copy-example-url').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            let url = this.dataset.url;
            
            try {
                await navigator.clipboard.writeText(url);
                catalogToast('URL copied to clipboard!', 'success');
                let originalText = this.innerHTML;
                this.innerHTML = '<i class="fa-regular fa-check"></i> Copied!';
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 1500);
            } catch (err) {
                console.error('Failed to copy:', err);
                catalogToast('Failed to copy URL', 'error');
            }
        });
    });

    // Add to Cart — sensitive type always read from the checked radio in the DOM.
    document.querySelectorAll('.buy-now').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (this.disabled || this.dataset.busy === '1') return;

            let id = parseInt(this.dataset.id, 10);
            let basePrice = parseFloat(this.dataset.basePrice);
            let name = this.dataset.name;
            if (!id || Number.isNaN(id)) {
                catalogToast('Could not add to cart.', 'error');
                return;
            }

            const selected = getSelectedSensitiveForSite(id);
            const sensitiveType = selected.type;
            const additionalPrice = selected.additionalPrice || 0;
            if (Number.isFinite(selected.basePrice)) {
                basePrice = selected.basePrice;
            }
            const finalPrice = (Number.isFinite(basePrice) ? basePrice : 0) + additionalPrice;

            if (typeof window.addToCart !== 'function') {
                catalogToast('Cart is not ready. Refresh the page and try again.', 'error');
                return;
            }

            const btn = this;
            const originalText = btn.innerHTML;
            btn.dataset.busy = '1';
            btn.disabled = true;

            Promise.resolve(window.addToCart(id, name, finalPrice, sensitiveType, additionalPrice, basePrice))
                .then(function (result) {
                    if (result && result.ok === false) return;
                    btn.innerHTML = '<i class="fa-solid fa-check" aria-hidden="true"></i> Added!';
                    setTimeout(function () {
                        btn.innerHTML = originalText;
                        // Re-apply selected add-on price after the temporary "Added!" label.
                        syncSensitiveSelectionUi(id);
                    }, 1000);
                })
                .finally(function () {
                    btn.dataset.busy = '0';
                    btn.disabled = false;
                });
        });
    });

    // Favorite functionality (desktop table + mobile cards stay in sync)
    document.querySelectorAll('.favorite-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = favorites.indexOf(id);
            const wasAdded = index === -1;

            if (wasAdded) {
                favorites.push(id);
            } else {
                favorites.splice(index, 1);
                // On Favorites Only view, remove the site from the list immediately
                if (CatalogConfig.favoritesFilter) {
                    hideCatalogSite(id);
                }
            }

            updateButtonStates();

            /* Optimistic: put the previous list back if the save is refused, so the
               heart never claims a favourite the server did not keep. */
            const previousFavorites = wasAdded
                ? favorites.filter((f) => f !== id)
                : favorites.concat([id]);
            saveFavorites().then(function (ok) {
                if (ok) return;
                favorites = previousFavorites;
                updateButtonStates();
                if (!wasAdded && CatalogConfig.favoritesFilter) {
                    showCatalogSite(id);
                }
            });

            catalogToast(
                wasAdded ? `${name} added to favorites!` : `${name} removed from favorites!`,
                wasAdded ? 'success' : 'warning',
                {
                    actionLabel: 'Undo',
                    onAction: function () {
                        const i = favorites.indexOf(id);
                        if (wasAdded) {
                            if (i !== -1) favorites.splice(i, 1);
                        } else {
                            if (i === -1) favorites.push(id);
                            if (CatalogConfig.favoritesFilter) {
                                showCatalogSite(id);
                            }
                        }
                        updateButtonStates();
                        saveFavorites();
                    }
                }
            );
        });
    });

    // Blacklist functionality — hide from catalog; show again under Blacklisted Only / after unblock
    document.querySelectorAll('.blacklist-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            let id = parseInt(this.dataset.id);
            let name = this.dataset.name;
            let index = blacklist.indexOf(id);
            const wasBlacklisted = index === -1;

            if (wasBlacklisted) {
                blacklist.push(id);
                // Main catalog: remove immediately (desktop row + mobile card)
                if (!CatalogConfig.blacklistFilter) {
                    hideCatalogSite(id);
                }
            } else {
                blacklist.splice(index, 1);
                if (CatalogConfig.blacklistFilter) {
                    // Blacklisted Only view: site no longer belongs here
                    hideCatalogSite(id);
                } else {
                    showCatalogSite(id);
                }
            }

            updateButtonStates();

            /* Blacklisting hides the row, so a failed save would hide a site the
               server still lists. Restore both list and row if it is refused. */
            const previousBlacklist = wasBlacklisted
                ? blacklist.filter((b) => b !== id)
                : blacklist.concat([id]);
            saveBlacklist().then(function (ok) {
                if (ok) return;
                blacklist = previousBlacklist;
                updateButtonStates();
                if (wasBlacklisted && !CatalogConfig.blacklistFilter) {
                    showCatalogSite(id);
                } else if (!wasBlacklisted && CatalogConfig.blacklistFilter) {
                    showCatalogSite(id);
                }
            });

            catalogToast(
                wasBlacklisted ? `${name} has been blacklisted!` : `${name} removed from blacklist!`,
                wasBlacklisted ? 'warning' : 'success',
                {
                    actionLabel: 'Undo',
                    onAction: function () {
                        const i = blacklist.indexOf(id);
                        if (wasBlacklisted) {
                            if (i !== -1) blacklist.splice(i, 1);
                            if (!CatalogConfig.blacklistFilter) {
                                showCatalogSite(id);
                            }
                        } else {
                            if (i === -1) blacklist.push(id);
                            if (CatalogConfig.blacklistFilter) {
                                showCatalogSite(id);
                            } else {
                                hideCatalogSite(id);
                            }
                        }
                        updateButtonStates();
                        saveBlacklist();
                    }
                }
            );
        });
    });
});

// Safety net: hide any blacklisted sites still rendered on the main catalog
if (!CatalogConfig.blacklistFilter) {
document.querySelectorAll('.site-row[data-id], .catalog-mobile-card[data-id]').forEach(el => {
    let id = parseInt(el.dataset.id);
    if (blacklist.includes(id)) {
        hideCatalogSite(id);
    }
});
}

document.addEventListener('click', async function (e) {
    const claimBtn = e.target.closest('.btn-claim-site');
    if (claimBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (!window.Swal || typeof Swal.fire !== 'function') return;
        if (!CatalogConfig.routes || !CatalogConfig.routes.siteClaim) return;

        const siteId = claimBtn.dataset.siteId;
        const siteName = claimBtn.dataset.siteName || 'this website';
        const siteUrl = claimBtn.dataset.siteUrl || '';
        const contactEmail = CatalogConfig.contactEmail || '';
        const esc = (value) => String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

        const { value: form } = await Swal.fire({
            title: 'Claim this website',
            html: `<p class="small text-muted mb-2 text-start">If you own <strong>${esc(siteName)}</strong>, submit a claim for review. Include proof of ownership.</p>
                   <input id="swal-claim-name" class="swal2-input" placeholder="Website name (as listed)" value="${esc(siteName)}">
                   <input id="swal-claim-email" class="swal2-input" placeholder="Contact email" value="${esc(contactEmail)}">
                   <textarea id="swal-claim-proof" class="swal2-textarea" placeholder="Proof of ownership (domain registrar, CMS access, etc.)"></textarea>`,
            showCancelButton: true,
            confirmButtonText: 'Submit claim',
            confirmButtonColor: '#75787B',
            cancelButtonColor: '#9ca3af',
            focusConfirm: false,
            preConfirm: () => {
                const website_name = document.getElementById('swal-claim-name').value.trim();
                const contact_email = document.getElementById('swal-claim-email').value.trim();
                const proof_message = document.getElementById('swal-claim-proof').value.trim();
                if (proof_message.length < 20) {
                    Swal.showValidationMessage('Please add at least 20 characters of ownership proof.');
                    return false;
                }
                return {
                    site_id: parseInt(siteId, 10),
                    website_name: website_name || siteName,
                    website_url: siteUrl || undefined,
                    contact_email: contact_email || undefined,
                    proof_message,
                };
            },
        });
        if (!form) return;

        const res = await fetch(CatalogConfig.routes.siteClaim, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CatalogConfig.csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(form),
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done', confirmButtonColor: '#75787B' });
        return;
    }

    const btn = e.target.closest('.btn-suggest-website');
    if (!btn) return;
    const prefill = btn.dataset.search || document.querySelector('input[name="search"]')?.value || '';
    const { value: form } = await Swal.fire({
        title: 'Suggest a website',
        html: `<p class="small text-muted mb-2">Can’t find a publisher site? Suggest it and we’ll try to include it.</p>
               <input id="swal-site-name" class="swal2-input" placeholder="Website name" value="${prefill.replace(/"/g, '&quot;')}">
               <input id="swal-site-url" class="swal2-input" placeholder="https://example.com">
               <textarea id="swal-site-notes" class="swal2-textarea" placeholder="Why should we add it? (optional)"></textarea>`,
        showCancelButton: true,
        confirmButtonText: 'Submit suggestion',
        confirmButtonColor: '#1a585e',
        preConfirm: () => {
            const website_name = document.getElementById('swal-site-name').value.trim();
            const website_url = document.getElementById('swal-site-url').value.trim();
            const notes = document.getElementById('swal-site-notes').value.trim();
            if (!website_name || !website_url) {
                Swal.showValidationMessage('Website name and URL are required');
                return false;
            }
            return { website_name, website_url, notes, search_query: prefill };
        },
    });
    if (!form) return;
    const res = await fetch(CatalogConfig.routes.websiteSuggestionsStore, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CatalogConfig.csrfToken,
        },
        body: JSON.stringify(form),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
});
