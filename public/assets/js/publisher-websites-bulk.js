/* Publisher My Sites — guided bulk URL+price paste/CSV (expects PublisherWebsitesConfig.maxBulkRows) */
(function () {
    const body = document.getElementById('bulkUrlPriceBody');
    const addBtn = document.getElementById('bulkAddRowBtn');
    const pasteArea = document.getElementById('bulkPasteUrls');
    const pasteBtn = document.getElementById('bulkPasteUrlsBtn');
    const pasteError = document.getElementById('bulkPasteUrlsError');
    const pasteSuccess = document.getElementById('bulkPasteUrlsSuccess');
    const sheetFile = document.getElementById('bulkSheetFile');
    const sheetFileName = document.getElementById('bulkSheetFileName');
    const templateBtn = document.getElementById('bulkSheetTemplateBtn');
    if (!body || !addBtn) return;

    const MAX_ROWS = (window.PublisherWebsitesConfig && Number(window.PublisherWebsitesConfig.maxBulkRows)) || 200;

    function reindexRows() {
        Array.from(body.querySelectorAll('.bulk-url-price-row')).forEach(function (tr, i) {
            // Match on input type as well as name: rows added by the CSV import
            // or the Add row button start life without a name, and a selector
            // that only found already-named inputs left every row past the two
            // Blade renders unnamed — so the browser never submitted them and a
            // 50-row import silently saved 2.
            const url = tr.querySelector('input[name*="[url]"]') || tr.querySelector('input[type="url"]');
            const price = tr.querySelector('input[name*="[price]"]') || tr.querySelector('input[type="number"]');
            if (url) url.name = 'sites[' + i + '][url]';
            if (price) price.name = 'sites[' + i + '][price]';
        });
    }

    function syncRemoveButtons() {
        const rows = body.querySelectorAll('.bulk-url-price-row');
        rows.forEach(function (tr) {
            const btn = tr.querySelector('.bulk-remove-row');
            if (btn) btn.disabled = rows.length <= 2;
        });
    }

    function createRow(urlValue, priceValue) {
        const tr = document.createElement('tr');
        tr.className = 'bulk-url-price-row';
        // Named here as well as in reindexRows so a row is submittable the
        // moment it exists, whatever order the callers run in.
        const seq = body.querySelectorAll('.bulk-url-price-row').length;
        tr.innerHTML =
            '<td><input type="url" name="sites[' + seq + '][url]" class="form-control form-control-sm" placeholder="https://example.com" required></td>' +
            '<td><input type="number" name="sites[' + seq + '][price]" step="0.01" min="0" class="form-control form-control-sm" placeholder="99" required></td>' +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">&times;</button></td>';
        const urlInput = tr.querySelector('input[type="url"]');
        const priceInput = tr.querySelector('input[type="number"]');
        if (urlInput && urlValue) urlInput.value = urlValue;
        if (priceInput && priceValue !== undefined && priceValue !== null && priceValue !== '') {
            priceInput.value = priceValue;
        }
        return tr;
    }

    function ensureRowCount(n) {
        n = Math.max(2, Math.min(MAX_ROWS, n));
        let rows = body.querySelectorAll('.bulk-url-price-row');
        while (rows.length < n) {
            body.appendChild(createRow('', ''));
            rows = body.querySelectorAll('.bulk-url-price-row');
        }
        while (rows.length > n) {
            rows[rows.length - 1].remove();
            rows = body.querySelectorAll('.bulk-url-price-row');
        }
        reindexRows();
        syncRemoveButtons();
    }

    function clearImportMessages() {
        if (pasteError) {
            pasteError.classList.add('d-none');
            pasteError.textContent = '';
        }
        if (pasteSuccess) {
            pasteSuccess.classList.add('d-none');
            pasteSuccess.textContent = '';
        }
    }

    function showImportError(msg) {
        if (pasteSuccess) {
            pasteSuccess.classList.add('d-none');
            pasteSuccess.textContent = '';
        }
        if (pasteError) {
            pasteError.textContent = msg;
            pasteError.classList.remove('d-none');
        }
    }

    function showImportSuccess(msg) {
        if (pasteError) {
            pasteError.classList.add('d-none');
            pasteError.textContent = '';
        }
        if (pasteSuccess) {
            pasteSuccess.textContent = msg;
            pasteSuccess.classList.remove('d-none');
        }
    }

    function stripCell(token) {
        return String(token ?? '').trim().replace(/^["']|["']$/g, '').trim();
    }

    /** Pure number / price-like token (never treat as a website). */
    function isNumericToken(token) {
        const raw = stripCell(token);
        if (!raw) return false;
        return /^€?\s*\d{1,3}([.,]\d{3})*([.,]\d{1,2})?\s*€?$/.test(raw)
            || /^€?\s*\d+([.,]\d{1,2})?\s*€?$/.test(raw);
    }

    function normalizeUrl(token) {
        let u = stripCell(token);
        if (!u) return null;
        // Prices like "99" / "150.5" become https://0.0.0.99 via URL() — reject those.
        if (isNumericToken(u)) return null;
        if (/\s/.test(u)) return null;
        if (!/^https?:\/\//i.test(u)) {
            u = 'https://' + u;
        }
        try {
            const parsed = new URL(u);
            const host = (parsed.hostname || '').toLowerCase().replace(/^www\./, '');
            if (!host) return null;
            // Require a real domain/host with a letter (blocks IPv4 from bare numbers).
            if (!/[a-z]/i.test(host)) return null;
            if (host.indexOf('.') === -1 && host !== 'localhost') return null;
            if ((parsed.pathname === '/' || parsed.pathname === '') && !parsed.search && !parsed.hash) {
                return parsed.protocol + '//' + parsed.host;
            }
            return parsed.href;
        } catch (e) {
            return null;
        }
    }

    function parsePriceToken(token) {
        if (token === undefined || token === null) return null;
        let raw = stripCell(token);
        if (!raw) return null;
        // Never parse a URL-looking token as a price.
        if (/^https?:\/\//i.test(raw) || /[a-z]/i.test(raw.replace(/€/g, ''))) {
            // allow only digits, separators, euro — if letters remain after stripping euro, not a price
            const withoutEuro = raw.replace(/€/gi, '').trim();
            if (/[a-z]/i.test(withoutEuro)) return null;
        }
        raw = raw.replace(/€/g, '').replace(/\s/g, '');
        // European 1.234,56 or plain 1234,56
        if (/^\d{1,3}(\.\d{3})+,\d{1,2}$/.test(raw) || /^\d+,\d{1,2}$/.test(raw)) {
            raw = raw.replace(/\./g, '').replace(',', '.');
        } else {
            raw = raw.replace(/,/g, '');
        }
        if (!/^\d+(\.\d{1,2})?$/.test(raw)) return null;
        const n = Number(raw);
        if (!isFinite(n) || n < 0) return null;
        return Math.round(n * 100) / 100;
    }

    function splitCsvLine(line) {
        const out = [];
        let cur = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    cur += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
                continue;
            }
            if ((ch === ',' || ch === ';' || ch === '\t') && !inQuotes) {
                out.push(cur.trim());
                cur = '';
                continue;
            }
            cur += ch;
        }
        out.push(cur.trim());
        return out;
    }

    /** Split a single line into cells (CSV/TSV) or "url price" / "url €99". */
    function lineToCells(line) {
        const trimmed = String(line || '').trim();
        if (!trimmed) return [];
        if (/[,\t;]/.test(trimmed)) {
            return splitCsvLine(trimmed).map(stripCell).filter(Boolean);
        }
        // Space-separated: https://a.com 99   or   a.com €150
        const spaceParts = trimmed.split(/\s+/).filter(Boolean);
        if (spaceParts.length >= 2) {
            const last = spaceParts[spaceParts.length - 1];
            const head = spaceParts.slice(0, -1).join(' ');
            if (normalizeUrl(head) && parsePriceToken(last) !== null) {
                return [head, last];
            }
            if (parsePriceToken(spaceParts[0]) !== null && normalizeUrl(spaceParts.slice(1).join(' '))) {
                return [spaceParts[0], spaceParts.slice(1).join(' ')];
            }
        }
        return [trimmed];
    }

    function looksLikeHeader(cells) {
        const joined = cells.join(' ').toLowerCase();
        if ((/(url|website|domain|site)/.test(joined) && /(price|€|eur|cost)/.test(joined))) {
            return true;
        }
        return joined === 'url' || joined === 'website url' || joined === 'price' || joined === 'website';
    }

    function looksLikePrice(token) {
        return parsePriceToken(token) !== null;
    }

    /**
     * Parse paste/CSV into [{url, price|null}].
     * Prefers url+price pairs; falls back to URL-only tokens.
     */
    function parseUrlPriceImport(text) {
        const raw = String(text || '').replace(/^\uFEFF/, '').trim();
        if (!raw) {
            return { rows: [], mode: 'empty', truncated: false };
        }

        const lines = raw.split(/\r\n|\n|\r/).map(function (l) { return l.trim(); }).filter(Boolean);
        const pairRows = [];
        const seen = {};
        let truncated = false;

        function addPair(urlRaw, priceRaw) {
            const url = normalizeUrl(urlRaw);
            if (!url) return false;
            let host = '';
            try { host = new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) { return false; }
            if (!host || seen[host]) return false;
            seen[host] = true;
            if (pairRows.length >= MAX_ROWS) {
                truncated = true;
                return false;
            }
            const price = priceRaw === undefined || priceRaw === null || priceRaw === ''
                ? null
                : parsePriceToken(priceRaw);
            pairRows.push({ url: url, price: price });
            return true;
        }

        let started = false;
        lines.forEach(function (line) {
            const nonEmpty = lineToCells(line);
            if (!nonEmpty.length) return;
            if (!started && looksLikeHeader(nonEmpty)) {
                started = true;
                return;
            }
            started = true;

            if (nonEmpty.length >= 2) {
                const a = nonEmpty[0];
                const b = nonEmpty[1];
                if (normalizeUrl(a) && looksLikePrice(b)) {
                    addPair(a, b);
                    return;
                }
                if (looksLikePrice(a) && normalizeUrl(b)) {
                    addPair(b, a);
                    return;
                }
                if (normalizeUrl(a) && parsePriceToken(b) !== null) {
                    addPair(a, b);
                    return;
                }
            }

            if (nonEmpty.length === 1 && normalizeUrl(nonEmpty[0])) {
                addPair(nonEmpty[0], null);
            }
        });

        const withPrice = pairRows.filter(function (r) { return r.price !== null; }).length;
        if (pairRows.length >= 2) {
            return {
                rows: pairRows,
                mode: withPrice > 0 ? 'pairs' : 'urls',
                truncated: truncated,
            };
        }

        // Fallback: URL-only token soup (legacy paste of many URLs on one line)
        const tokens = raw.split(/[\s,;]+/).map(function (t) { return t.trim(); }).filter(Boolean);
        const urls = [];
        const seenUrl = {};
        tokens.forEach(function (token) {
            if (isNumericToken(token) || looksLikePrice(token)) return;
            const url = normalizeUrl(token);
            if (!url) return;
            let host = '';
            try { host = new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) { return; }
            if (!host || seenUrl[host]) return;
            seenUrl[host] = true;
            if (urls.length >= MAX_ROWS) {
                truncated = true;
                return;
            }
            urls.push({ url: url, price: null });
        });
        return { rows: urls, mode: 'urls', truncated: truncated };
    }

    function rowUrlInput(tr) {
        return tr.querySelector('input[name*="[url]"]') || tr.querySelector('input[type="url"]');
    }

    function rowPriceInput(tr) {
        return tr.querySelector('input[name*="[price]"]') || tr.querySelector('input[type="number"]');
    }

    function applyImportRows(rows, mode, truncated) {
        if (!rows || rows.length < 2) {
            showImportError('Need at least 2 valid website URLs. Use one per line, or url,price (CSV / Excel paste).');
            return false;
        }

        ensureRowCount(rows.length);
        const trs = body.querySelectorAll('.bulk-url-price-row');
        rows.forEach(function (row, i) {
            const urlInput = rowUrlInput(trs[i]);
            const priceInput = rowPriceInput(trs[i]);
            if (urlInput) urlInput.value = row.url || '';
            if (priceInput) {
                priceInput.value = (row.price !== null && row.price !== undefined) ? String(row.price) : '';
            }
        });
        reindexRows();
        syncRemoveButtons();

        const priced = rows.filter(function (r) { return r.price !== null && r.price !== undefined; }).length;
        let msg = 'Loaded ' + rows.length + ' site' + (rows.length === 1 ? '' : 's');
        if (priced > 0) {
            msg += ' (' + priced + ' with price)';
        } else {
            msg += ' — fill € prices in the table before submit';
        }
        if (truncated) {
            msg += '. Maximum ' + MAX_ROWS + ' rows; extras were skipped.';
        }
        showImportSuccess(msg + '.');
        return true;
    }

    function importFromText(text) {
        clearImportMessages();
        const parsed = parseUrlPriceImport(text);
        return applyImportRows(parsed.rows, parsed.mode, parsed.truncated);
    }

    addBtn.addEventListener('click', function () {
        if (body.querySelectorAll('.bulk-url-price-row').length >= MAX_ROWS) return;
        body.appendChild(createRow('', ''));
        reindexRows();
        syncRemoveButtons();
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.bulk-remove-row');
        if (!btn) return;
        const rows = body.querySelectorAll('.bulk-url-price-row');
        if (rows.length <= 2) return;
        btn.closest('tr')?.remove();
        reindexRows();
        syncRemoveButtons();
    });

    if (pasteBtn && pasteArea) {
        pasteBtn.addEventListener('click', function () {
            importFromText(pasteArea.value);
        });
    }

    if (sheetFile) {
        sheetFile.addEventListener('change', function () {
            clearImportMessages();
            const file = sheetFile.files && sheetFile.files[0];
            if (!file) return;
            const name = file.name || 'sheet';
            if (sheetFileName) sheetFileName.textContent = name;

            const lower = name.toLowerCase();
            if (/\.(xlsx|xls|ods)$/.test(lower)) {
                showImportError('Please save the sheet as CSV (File → Save As → CSV) or copy the URL + Price columns and paste them below.');
                sheetFile.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function () {
                const text = String(reader.result || '');
                importFromText(text);
                sheetFile.value = '';
            };
            reader.onerror = function () {
                showImportError('Could not read that file. Try CSV or paste the columns instead.');
                sheetFile.value = '';
            };
            reader.readAsText(file);
        });
    }

    if (templateBtn) {
        templateBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const csv = 'Website URL,Price\nhttps://site-one.example,99\nhttps://site-two.example,150\n';
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'bulk-sites-url-price-sample.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    }

    // Expose for tests / console debugging
    window.__bulkParseUrlPriceImport = parseUrlPriceImport;

    reindexRows();
    syncRemoveButtons();
})();
