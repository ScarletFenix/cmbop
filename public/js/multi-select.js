/**
 * Click-to-toggle multi-select (Catalog-parity keyboard UX).
 * Writes pipe-separated values into a hidden input (category names may contain commas).
 *
 * Catalog main-search category behaviour:
 * - Enter: add sole visible match, or the keyboard-focused row
 * - Backspace/Delete (empty typeahead): peel last selected tag
 * - ArrowUp/ArrowDown: move keyboard focus among visible options
 * - Escape: close dropdown
 * - Empty filter: show "No … found" empty state
 *
 * Usage:
 *   const ms = window.initMultiSelect({
 *     wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId,
 *     emptyId: 'categoryEmpty', // optional
 *     maxSelections: 7,
 *     placeholderText: 'Select categories (max 7)...'
 *   });
 *   ms.setSelectedItems(['Tech'], ['Tech']);
 */
(function (global) {
  function closeAllMultiSelectDropdowns(exceptDropdown) {
    if (!global.jQuery) return;
    const $ = global.jQuery;
    $('.multi-select-dropdown').each(function () {
      const $dropdown = $(this);
      if (exceptDropdown && $dropdown.is(exceptDropdown)) return;
      hideDropdown($dropdown);
    });
  }

  function hideDropdown($dropdown) {
    if (!$dropdown || !$dropdown.length) return;
    $dropdown.removeClass('show multi-select-dropdown--fixed');
    $dropdown.css({
      position: '',
      top: '',
      left: '',
      width: '',
      right: '',
      zIndex: '',
    });
    const placeholder = $dropdown.data('msPlaceholder');
    if (placeholder && placeholder.length && !$dropdown.parent().is(placeholder)) {
      placeholder.append($dropdown);
    }
    $dropdown.removeData('msPlaceholder');
    $dropdown.removeData('msAnchor');
    $dropdown.removeData('msInstance');
  }

  function positionDropdown($dropdown, $anchor) {
    if (!$dropdown.length || !$anchor.length) return;
    const rect = $anchor[0].getBoundingClientRect();
    const width = Math.max(rect.width, 180);
    let left = rect.left;
    const maxLeft = Math.max(8, window.innerWidth - width - 8);
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;

    const estimatedHeight = Math.min(260, window.innerHeight - 16);
    let top = rect.bottom + 4;
    if (top + estimatedHeight > window.innerHeight - 8) {
      top = Math.max(8, rect.top - estimatedHeight - 4);
    }

    $dropdown.addClass('multi-select-dropdown--fixed');
    $dropdown.css({
      position: 'fixed',
      top: top + 'px',
      left: left + 'px',
      width: width + 'px',
      right: 'auto',
      zIndex: 2000,
    });
  }

  function showDropdown($dropdown, $anchor, instance) {
    if (!$dropdown.data('msPlaceholder')) {
      $dropdown.data('msPlaceholder', $dropdown.parent());
    }
    $dropdown.data('msAnchor', $anchor);
    if (instance) {
      $dropdown.data('msInstance', instance);
    }
    if (!$dropdown.parent().is(document.body)) {
      global.jQuery(document.body).append($dropdown);
    }
    $dropdown.addClass('show');
    positionDropdown($dropdown, $anchor);
  }

  function initMultiSelect(opts) {
    if (!global.jQuery) {
      console.error('initMultiSelect requires jQuery');
      return null;
    }
    const $ = global.jQuery;
    const {
      wrapperId,
      inputId,
      dropdownId,
      optionsId,
      hiddenInputId,
      searchId,
      emptyId = null,
      maxSelections = null,
      placeholderText = 'Select options...',
    } = opts;

    let selectedItems = [];
    let focusIndex = -1;
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);
    const emptyEl = emptyId
      ? $(`#${emptyId}`)
      : wrapper.find('.multi-select-empty').add(dropdown.find('.multi-select-empty')).first();

    if (!wrapper.length || !input.length || !dropdown.length) {
      return null;
    }

    const instance = {
      addItem,
      removeItem,
      getSelectedItems: () => selectedItems.slice(),
      clearSelections,
      setSelectedItems,
      updateDisplay,
      isOpen: () => dropdown.hasClass('show'),
      open,
      close,
      removeLast,
    };

    function updateDisplay() {
      input.empty();
      if (selectedItems.length === 0) {
        input.html(`<span class="multi-select-placeholder">${placeholderText}</span>`);
      } else {
        // Tags must live in a wrapping flex container — direct children of
        // .multi-select-input (no flex-wrap) blow out table cells / rows.
        const $tags = $('<span class="multi-select-tags"></span>');
        selectedItems.forEach((item) => {
          const tag = $(`
            <span class="multi-select-tag">
              ${$('<div>').text(item.label).html()}
              <span class="remove-tag" data-value="${$('<div>').text(item.value).html()}">&times;</span>
            </span>
          `);
          tag.find('.remove-tag').on('click', function (e) {
            e.stopPropagation();
            removeItem(item.value);
          });
          $tags.append(tag);
        });
        input.append($tags);
      }
      hiddenInput.val(selectedItems.map((item) => item.value).join('|'));
      // jQuery .trigger() does not reliably reach native addEventListener on ancestors
      // (e.g. form draft autosave). Dispatch a bubbling native change as well.
      const el = hiddenInput.get(0);
      if (el) {
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
      hiddenInput.trigger('change');
      if (dropdown.hasClass('show')) {
        positionDropdown(dropdown, input);
      }
    }

    function addItem(value, label) {
      if (maxSelections && selectedItems.length >= maxSelections) {
        if (global.Swal) {
          global.Swal.fire({
            icon: 'warning',
            title: `Maximum ${maxSelections} selections allowed`,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
          });
        }
        return false;
      }
      if (!selectedItems.some((item) => item.value === value)) {
        selectedItems.push({ value, label });
        updateDisplay();
        updateOptionsHighlight();
        return true;
      }
      return false;
    }

    function removeItem(value) {
      selectedItems = selectedItems.filter((item) => item.value !== value);
      updateDisplay();
      updateOptionsHighlight();
    }

    function removeLast() {
      if (!selectedItems.length) return false;
      removeItem(selectedItems[selectedItems.length - 1].value);
      return true;
    }

    function optionValue($el) {
      // Prefer attr over jQuery .data(): .data() caches/coerces and can disagree
      // with niches that contain "&" after Blade entity-encodes data-value.
      return String($el.attr('data-value') ?? '');
    }

    function optionLabel($el) {
      return String($el.attr('data-label') ?? $el.text() ?? '');
    }

    function visibleOptions() {
      return optionsContainer.find('.multi-select-option').filter(function () {
        return !$(this).hasClass('hidden');
      });
    }

    function clearKeyboardFocus() {
      optionsContainer.find('.multi-select-option').removeClass('is-keyboard-focus');
      focusIndex = -1;
    }

    function setKeyboardFocus(index) {
      const $visible = visibleOptions();
      clearKeyboardFocus();
      if (!$visible.length) return;
      if (index < 0) index = $visible.length - 1;
      if (index >= $visible.length) index = 0;
      focusIndex = index;
      const $opt = $visible.eq(focusIndex);
      $opt.addClass('is-keyboard-focus');
      const node = $opt.get(0);
      if (node && typeof node.scrollIntoView === 'function') {
        node.scrollIntoView({ block: 'nearest' });
      }
    }

    function updateOptionsHighlight() {
      optionsContainer.find('.multi-select-option').each(function () {
        const $this = $(this);
        const value = optionValue($this);
        $this.toggleClass(
          'selected',
          selectedItems.some((item) => item.value === value)
        );
      });
    }

    function syncEmptyState() {
      if (!emptyEl.length) return;
      const count = visibleOptions().length;
      emptyEl.toggleClass('d-none', count > 0);
    }

    function filterOptions(searchTerm) {
      const term = String(searchTerm || '').toLowerCase();
      optionsContainer.find('.multi-select-option').each(function () {
        const $this = $(this);
        const text = $this.text().toLowerCase();
        $this.toggleClass('hidden', !(term === '' || text.includes(term)));
      });
      clearKeyboardFocus();
      syncEmptyState();
    }

    function toggleOption($option) {
      if (!$option || !$option.length || $option.hasClass('hidden')) return false;
      const value = optionValue($option);
      const label = optionLabel($option) || value;
      if ($option.hasClass('selected')) {
        removeItem(value);
        return true;
      }
      return addItem(value, label);
    }

    function selectSoleOrFocused() {
      const $visible = visibleOptions();
      if (focusIndex >= 0 && focusIndex < $visible.length) {
        return toggleOption($visible.eq(focusIndex));
      }
      if ($visible.length === 1) {
        return toggleOption($visible.eq(0));
      }
      return false;
    }

    function open() {
      closeAllMultiSelectDropdowns(dropdown);
      $('.single-select-dropdown').removeClass('show');
      showDropdown(dropdown, input, instance);
      input.attr('aria-expanded', 'true');
      searchInput.focus();
      filterOptions(searchInput.val() || '');
    }

    function close() {
      hideDropdown(dropdown);
      input.attr('aria-expanded', 'false');
      clearKeyboardFocus();
    }

    input.on('click', function (e) {
      e.stopPropagation();
      if (dropdown.hasClass('show')) {
        close();
      } else {
        open();
      }
    });

    input.on('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
        e.preventDefault();
        if (!dropdown.hasClass('show')) {
          open();
        } else if (e.key === 'ArrowDown') {
          setKeyboardFocus(0);
          searchInput.focus();
        }
        return;
      }
      if ((e.key === 'Backspace' || e.key === 'Delete') && selectedItems.length) {
        e.preventDefault();
        removeLast();
      }
    });

    dropdown.on('click', function (e) {
      e.stopPropagation();
    });

    searchInput.on('input', function () {
      filterOptions($(this).val());
    });

    searchInput.on('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
        input.focus();
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setKeyboardFocus(focusIndex < 0 ? 0 : focusIndex + 1);
        return;
      }

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        setKeyboardFocus(focusIndex < 0 ? visibleOptions().length - 1 : focusIndex - 1);
        return;
      }

      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        if (selectSoleOrFocused()) {
          searchInput.val('');
          filterOptions('');
          searchInput.focus();
          if (dropdown.hasClass('show')) {
            positionDropdown(dropdown, input);
          }
        }
        return;
      }

      if (e.key === 'Backspace' || e.key === 'Delete') {
        if (String(searchInput.val() || '').length === 0 && selectedItems.length) {
          e.preventDefault();
          removeLast();
        }
      }
    });

    optionsContainer.on('click', '.multi-select-option', function () {
      const $option = $(this);
      if ($option.hasClass('hidden')) return;
      toggleOption($option);
      // Keep dropdown open (Catalog-like multi-add).
      searchInput.focus();
    });

    function setSelectedItems(values, labels) {
      selectedItems = [];
      for (let i = 0; i < values.length; i++) {
        if (values[i]) {
          selectedItems.push({ value: values[i], label: labels[i] || values[i] });
        }
      }
      updateDisplay();
      updateOptionsHighlight();
    }

    function clearSelections() {
      selectedItems = [];
      updateDisplay();
      updateOptionsHighlight();
      searchInput.val('');
      filterOptions('');
    }

    updateDisplay();
    syncEmptyState();

    return instance;
  }

  if (!global.__multiSelectOutsideClickBound) {
    global.__multiSelectOutsideClickBound = true;
    document.addEventListener('click', function () {
      closeAllMultiSelectDropdowns();
    });
    global.addEventListener('scroll', function () {
      if (!global.jQuery) return;
      global.jQuery('.multi-select-dropdown.show').each(function () {
        const $dropdown = global.jQuery(this);
        const $anchor = $dropdown.data('msAnchor');
        if ($anchor && $anchor.length) {
          positionDropdown($dropdown, $anchor);
        }
      });
    }, true);
    global.addEventListener('resize', function () {
      if (!global.jQuery) return;
      global.jQuery('.multi-select-dropdown.show').each(function () {
        const $dropdown = global.jQuery(this);
        const $anchor = $dropdown.data('msAnchor');
        if ($anchor && $anchor.length) {
          positionDropdown($dropdown, $anchor);
        }
      });
    });
  }

  global.initMultiSelect = initMultiSelect;
  global.closeAllMultiSelectDropdowns = closeAllMultiSelectDropdowns;
})(typeof window !== 'undefined' ? window : globalThis);
