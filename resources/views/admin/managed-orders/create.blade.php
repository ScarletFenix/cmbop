@extends('admin.layouts.app')

@section('title', 'Create managed order')

@section('content')
@php
    $slbFee = (float) ($slbWritingFee ?? 35);
    $oldSites = old('sites');
    if (! is_array($oldSites) || $oldSites === []) {
        $oldSites = [[
            'site_id' => old('site_id'),
            'quantity' => 1,
            'content_source' => old('content_source', 'buyer'),
            'content_url' => old('content_url'),
            'content_submission_id' => old('content_submission_id'),
            'writing_brief_notes' => old('writing_brief_notes'),
            'links' => [[
                'anchor' => old('anchor_text'),
                'url' => old('target_url'),
            ]],
        ]];
    }
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Create order · Managed by SLB',
        'subtitle' => 'Charge the advertiser wallet once and open managed desk row(s). The advertiser does not need to cart or confirm.',
        'actionUrl' => route('admin.managed-orders.index'),
        'actionLabel' => 'All managed',
        'actionIcon' => 'fa-arrow-left',
    ])

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Order details</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.managed-orders.confirm') }}"
                          id="managedCreateForm"
                          class="admin-deposits-filters vstack gap-3"
                          data-admin-select-no-submit="1"
                          data-admin-filter-live="1"
                          data-quote-url="{{ $quoteUrl }}"
                          data-library-search-url="{{ $librarySearchUrl }}"
                          data-slb-fee="{{ $slbFee }}">
                        @csrf

                        <div>
                            <label class="form-label" for="advertiser_id">Advertiser</label>
                            <select name="advertiser_id" id="advertiser_id" class="form-select" required
                                    data-admin-select-search="1"
                                    data-admin-select-search-url="{{ $advertiserSearchUrl }}"
                                    data-admin-select-search-label="Search name, email, or id"
                                    data-admin-select-search-empty="No advertisers match"
                                    data-admin-select-placeholder="Select advertiser…">
                                <option value="">Select advertiser…</option>
                                @if(old('advertiser_id'))
                                    <option value="{{ old('advertiser_id') }}" selected>Advertiser #{{ old('advertiser_id') }}</option>
                                @endif
                            </select>
                            <div id="advertiserBalanceBox" class="form-text mt-2 d-none">
                                Wallet balance: <strong id="advertiserBalanceLabel">—</strong>
                            </div>
                            <div id="advertiserBalanceWarn" class="alert alert-danger border-0 py-2 px-3 small mt-2 mb-0 d-none" role="status">
                                Balance is below the order total. Top up the advertiser wallet before charging.
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between">
                            <strong>Sites</strong>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addSiteBtn">
                                <i class="fa fa-plus" aria-hidden="true"></i> Add site
                            </button>
                        </div>
                        <div id="sitesList" class="vstack gap-3"></div>

                        <div>
                            <label class="form-label" for="managed_external_reference">External reference (optional)</label>
                            <input type="text" name="managed_external_reference" id="managed_external_reference"
                                   class="form-control" maxlength="500"
                                   value="{{ old('managed_external_reference') }}"
                                   placeholder="Email subject or thread link">
                            <div class="form-text">Stored on the managed desk for fulfilment tracking.</div>
                        </div>

                        <div>
                            <label class="form-label" for="managed_assignee_id">Assignee (optional)</label>
                            <select name="managed_assignee_id" id="managed_assignee_id" class="form-select">
                                <option value="">Unassigned</option>
                                @foreach($assignees as $staff)
                                    <option value="{{ $staff->id }}" @selected((int) old('managed_assignee_id') === (int) $staff->id)>{{ $staff->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="managed_notes">Internal notes (optional)</label>
                            <textarea name="managed_notes" id="managed_notes" class="form-control" rows="3" maxlength="5000">{{ old('managed_notes') }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary align-self-start" id="reviewChargeBtn">Review charge</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:1rem;">
                <div class="card-header bg-white border-0"><strong>Price summary</strong></div>
                <div class="card-body">
                    <div id="quoteLines" class="small text-muted mb-2">Choose an advertiser and site to see pricing.</div>
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="text-muted">Total</span>
                        <strong id="quoteTotalLabel">€0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <span class="text-muted">Wallet</span>
                        <span id="quoteBalanceLabel">—</span>
                    </div>
                    <p class="small text-muted mb-0">
                        Same advertiser-facing listing price and add-ons as checkout (incl. SLB writing €{{ number_format($slbFee, 0) }}).
                        Pack discounts apply for qty 3 / 5 / 10. Payment is wallet only — one debit.
                        The catalog publisher will not see this in Tasks and will not be paid through the platform.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="siteRowTemplate">
    <div class="border rounded-3 p-3 managed-site-row" data-site-index="__INDEX__">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <strong class="managed-site-heading">Site</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary managed-remove-site" title="Remove site">Remove</button>
        </div>
        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label">Site</label>
                <select name="sites[__INDEX__][site_id]" class="form-select managed-site-select" required
                        data-admin-select-search="1"
                        data-admin-select-search-url="{{ $siteSearchUrl }}"
                        data-admin-select-search-label="Search name, domain, or id"
                        data-admin-select-search-empty="No sites match"
                        data-admin-select-placeholder="Select site…">
                    <option value="">Select site…</option>
                </select>
                <div class="managed-site-details small text-muted mt-2 d-none"></div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Qty (pack)</label>
                <input type="number" name="sites[__INDEX__][quantity]" class="form-control managed-qty" min="1" max="10" value="1">
                <div class="form-text">3 / 5 / 10 for pack pricing</div>
            </div>
            <div class="col-12">
                <label class="form-label">Content source</label>
                <select name="sites[__INDEX__][content_source]" class="form-select managed-content-source" required>
                    <option value="buyer">Advertiser-provided article URL</option>
                    <option value="slb">SLB writes it (+€{{ number_format($slbFee, 0) }})</option>
                    <option value="library">Content Library</option>
                </select>
            </div>
            <div class="col-12 managed-content-url-wrap">
                <label class="form-label">Content URL</label>
                <input type="url" name="sites[__INDEX__][content_url]" class="form-control managed-content-url" maxlength="2000" placeholder="https://…">
                <div class="form-text managed-url-hint">Required when the advertiser supplies the article. Must be http(s).</div>
                <div class="invalid-feedback managed-url-error d-none">Enter a valid http(s) URL.</div>
            </div>
            <div class="col-12 managed-library-wrap d-none">
                <label class="form-label">Library article</label>
                <select name="sites[__INDEX__][content_submission_id]" class="form-select managed-library-select"
                        data-admin-select-search="1"
                        data-admin-select-search-url="{{ $librarySearchUrl }}"
                        data-admin-select-search-label="Search library articles"
                        data-admin-select-search-empty="No library articles"
                        data-admin-select-placeholder="Select article…">
                    <option value="">Select article…</option>
                </select>
                <div class="form-text">Respects the one-site-per-article lock.</div>
            </div>
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label mb-0">Anchor + target links</label>
                    <button type="button" class="btn btn-sm btn-outline-primary managed-add-link">Add link</button>
                </div>
                <div class="managed-links vstack gap-2"></div>
                <div class="form-text managed-links-cap">Capped by the site’s dofollow allowance.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Writer notes (optional)</label>
                <textarea name="sites[__INDEX__][writing_brief_notes]" class="form-control managed-writer-notes" rows="2" maxlength="5000" placeholder="Guidance for SLB writers or fulfilment"></textarea>
            </div>
        </div>
    </div>
</template>

<template id="linkRowTemplate">
    <div class="row g-2 align-items-end managed-link-row">
        <div class="col-md-5">
            <label class="form-label small">Anchor</label>
            <input type="text" name="sites[__S__][links][__L__][anchor]" class="form-control managed-link-anchor" maxlength="500" required>
        </div>
        <div class="col-md-6">
            <label class="form-label small">Target URL</label>
            <input type="url" name="sites[__S__][links][__L__][url]" class="form-control managed-link-url" maxlength="2000" placeholder="https://…" required>
            <div class="invalid-feedback managed-link-url-error d-none">Use a valid https:// URL.</div>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-secondary w-100 managed-remove-link" title="Remove link">&times;</button>
        </div>
    </div>
</template>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('managedCreateForm');
    if (!form) return;

    const sitesList = document.getElementById('sitesList');
    const siteTpl = document.getElementById('siteRowTemplate');
    const linkTpl = document.getElementById('linkRowTemplate');
    const advertiserSelect = document.getElementById('advertiser_id');
    const balanceBox = document.getElementById('advertiserBalanceBox');
    const balanceLabel = document.getElementById('advertiserBalanceLabel');
    const balanceWarn = document.getElementById('advertiserBalanceWarn');
    const reviewBtn = document.getElementById('reviewChargeBtn');
    const quoteLines = document.getElementById('quoteLines');
    const quoteTotalLabel = document.getElementById('quoteTotalLabel');
    const quoteBalanceLabel = document.getElementById('quoteBalanceLabel');
    const librarySearchBase = form.dataset.librarySearchUrl || '';
    const quoteUrl = form.dataset.quoteUrl || '';
    const csrf = form.querySelector('input[name="_token"]')?.value || '';

    let siteIndex = 0;
    let quoteTimer = null;
    let walletBalance = null;

    const oldSites = @json($oldSites);

    function isHttpsUrl(value) {
        try {
            const u = new URL(String(value || '').trim());
            return u.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function isHttpUrl(value) {
        try {
            const u = new URL(String(value || '').trim());
            return u.protocol === 'http:' || u.protocol === 'https:';
        } catch (e) {
            return false;
        }
    }

    function enhanceSelect(select) {
        if (!select || select.closest('.admin-deposits-theme-select')) return;
        if (window.AdminThemeSelects && typeof window.AdminThemeSelects.enhance === 'function') {
            window.AdminThemeSelects.enhance(select);
        }
    }

    function selectedOptionData(select) {
        const opt = select && select.options[select.selectedIndex];
        if (!opt || !opt.value) return null;
        const data = {};
        Array.from(opt.attributes).forEach(function (attr) {
            if (attr.name.indexOf('data-') === 0) {
                data[attr.name.slice(5).replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); })] = attr.value;
                data[attr.name.slice(5)] = attr.value;
            }
        });
        return data;
    }

    function maxLinksForRow(row) {
        const select = row.querySelector('.managed-site-select');
        const data = selectedOptionData(select);
        const max = data && data['max-links'] ? parseInt(data['max-links'], 10) : (data && data.max_links ? parseInt(data.max_links, 10) : 3);
        return Number.isFinite(max) && max > 0 ? max : 3;
    }

    function syncContentSource(row) {
        const source = row.querySelector('.managed-content-source');
        const urlWrap = row.querySelector('.managed-content-url-wrap');
        const libWrap = row.querySelector('.managed-library-wrap');
        const urlInput = row.querySelector('.managed-content-url');
        const libSelect = row.querySelector('.managed-library-select');
        const val = source ? source.value : 'buyer';
        if (urlWrap) urlWrap.classList.toggle('d-none', val !== 'buyer');
        if (libWrap) libWrap.classList.toggle('d-none', val !== 'library');
        if (urlInput) urlInput.required = val === 'buyer';
        if (libSelect) libSelect.required = val === 'library';
    }

    function syncSiteDetails(row) {
        const select = row.querySelector('.managed-site-select');
        const box = row.querySelector('.managed-site-details');
        const data = selectedOptionData(select);
        if (!box) return;
        if (!data || !select.value) {
            box.classList.add('d-none');
            box.textContent = '';
            return;
        }
        const parts = [];
        parts.push('Price ' + (data['advertiser-price-label'] || data.advertiser_price_label || '—'));
        if (data.da || data['da']) parts.push('DA ' + (data.da || data['da']));
        if (data.dr || data['dr']) parts.push('DR ' + (data.dr || data['dr']));
        if (data.country) parts.push(data.country);
        const dof = String(data.dofollow || data['dofollow'] || '') === '1' || String(data.dofollow || '') === 'true';
        parts.push(dof || String(data['link-type'] || data.link_type || '').toLowerCase() !== 'nofollow' ? 'Dofollow' : 'Nofollow');
        if (data.turnaround) parts.push('Turnaround ' + data.turnaround);
        parts.push('Max links ' + maxLinksForRow(row));
        box.textContent = parts.join(' · ');
        box.classList.remove('d-none');
        trimLinksToCap(row);
    }

    function trimLinksToCap(row) {
        const max = maxLinksForRow(row);
        const wrap = row.querySelector('.managed-links');
        if (!wrap) return;
        while (wrap.querySelectorAll('.managed-link-row').length > max) {
            wrap.lastElementChild.remove();
        }
        const addBtn = row.querySelector('.managed-add-link');
        if (addBtn) addBtn.disabled = wrap.querySelectorAll('.managed-link-row').length >= max;
        const cap = row.querySelector('.managed-links-cap');
        if (cap) cap.textContent = 'Up to ' + max + ' link pair' + (max === 1 ? '' : 's') + ' for this site.';
    }

    function addLinkRow(row, preset) {
        const wrap = row.querySelector('.managed-links');
        if (!wrap) return;
        const max = maxLinksForRow(row);
        if (wrap.querySelectorAll('.managed-link-row').length >= max) return;
        const idx = row.dataset.siteIndex;
        const linkIdx = wrap.querySelectorAll('.managed-link-row').length;
        const html = linkTpl.innerHTML
            .replaceAll('__S__', idx)
            .replaceAll('__L__', String(linkIdx));
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const linkRow = holder.firstElementChild;
        if (preset) {
            const a = linkRow.querySelector('.managed-link-anchor');
            const u = linkRow.querySelector('.managed-link-url');
            if (a) a.value = preset.anchor || '';
            if (u) u.value = preset.url || '';
        }
        wrap.appendChild(linkRow);
        trimLinksToCap(row);
        bindLinkValidation(linkRow);
    }

    function bindLinkValidation(linkRow) {
        const url = linkRow.querySelector('.managed-link-url');
        const err = linkRow.querySelector('.managed-link-url-error');
        if (!url) return;
        url.addEventListener('input', function () {
            const ok = !url.value || isHttpsUrl(url.value);
            url.classList.toggle('is-invalid', !ok);
            if (err) {
                err.classList.toggle('d-none', ok);
                err.style.display = ok ? 'none' : 'block';
            }
            scheduleQuote();
        });
        url.addEventListener('change', scheduleQuote);
    }

    function updateLibrarySearchUrls() {
        const advertiserId = advertiserSelect ? advertiserSelect.value : '';
        form.querySelectorAll('.managed-library-select').forEach(function (select) {
            let url = librarySearchBase;
            try {
                const u = new URL(librarySearchBase, window.location.origin);
                if (advertiserId) u.searchParams.set('advertiser_id', advertiserId);
                else u.searchParams.delete('advertiser_id');
                url = u.pathname + u.search;
            } catch (e) {}
            select.dataset.adminSelectSearchUrl = url;
        });
    }

    function bindSiteRow(row) {
        const siteSelect = row.querySelector('.managed-site-select');
        const source = row.querySelector('.managed-content-source');
        const qty = row.querySelector('.managed-qty');
        const contentUrl = row.querySelector('.managed-content-url');
        const removeBtn = row.querySelector('.managed-remove-site');
        const addLinkBtn = row.querySelector('.managed-add-link');

        enhanceSelect(siteSelect);
        enhanceSelect(row.querySelector('.managed-library-select'));
        enhanceSelect(source);

        if (siteSelect) {
            siteSelect.addEventListener('change', function () {
                syncSiteDetails(row);
                scheduleQuote();
            });
        }
        if (source) {
            source.addEventListener('change', function () {
                syncContentSource(row);
                scheduleQuote();
            });
        }
        if (qty) qty.addEventListener('change', scheduleQuote);
        if (contentUrl) {
            contentUrl.addEventListener('input', function () {
                const ok = !contentUrl.value || isHttpUrl(contentUrl.value);
                contentUrl.classList.toggle('is-invalid', !ok);
                const err = row.querySelector('.managed-url-error');
                if (err) {
                    err.classList.toggle('d-none', ok);
                    err.style.display = ok ? 'none' : 'block';
                }
                scheduleQuote();
            });
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (sitesList.querySelectorAll('.managed-site-row').length <= 1) return;
                row.remove();
                scheduleQuote();
            });
        }
        if (addLinkBtn) {
            addLinkBtn.addEventListener('click', function () {
                addLinkRow(row);
            });
        }
        row.querySelector('.managed-links')?.addEventListener('click', function (event) {
            const btn = event.target.closest('.managed-remove-link');
            if (!btn) return;
            const wrap = row.querySelector('.managed-links');
            if (wrap.querySelectorAll('.managed-link-row').length <= 1) return;
            btn.closest('.managed-link-row')?.remove();
            trimLinksToCap(row);
            scheduleQuote();
        });

        syncContentSource(row);
        syncSiteDetails(row);
        updateLibrarySearchUrls();
    }

    function addSiteRow(preset) {
        const idx = siteIndex++;
        const html = siteTpl.innerHTML.replaceAll('__INDEX__', String(idx));
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const row = holder.firstElementChild;
        sitesList.appendChild(row);

        if (preset) {
            const siteSelect = row.querySelector('.managed-site-select');
            if (preset.site_id && siteSelect) {
                const opt = document.createElement('option');
                opt.value = String(preset.site_id);
                opt.textContent = 'Site #' + preset.site_id;
                opt.selected = true;
                siteSelect.appendChild(opt);
            }
            const qty = row.querySelector('.managed-qty');
            if (qty && preset.quantity) qty.value = preset.quantity;
            const source = row.querySelector('.managed-content-source');
            if (source && preset.content_source) source.value = preset.content_source;
            const url = row.querySelector('.managed-content-url');
            if (url && preset.content_url) url.value = preset.content_url;
            const notes = row.querySelector('.managed-writer-notes');
            if (notes && preset.writing_brief_notes) notes.value = preset.writing_brief_notes;
            const lib = row.querySelector('.managed-library-select');
            if (lib && preset.content_submission_id) {
                const o = document.createElement('option');
                o.value = String(preset.content_submission_id);
                o.textContent = 'Article #' + preset.content_submission_id;
                o.selected = true;
                lib.appendChild(o);
            }
        }

        bindSiteRow(row);
        const links = (preset && Array.isArray(preset.links) && preset.links.length) ? preset.links : [{ anchor: '', url: '' }];
        links.forEach(function (link) { addLinkRow(row, link); });
        return row;
    }

    function collectPayload() {
        const sites = [];
        sitesList.querySelectorAll('.managed-site-row').forEach(function (row) {
            const siteId = row.querySelector('.managed-site-select')?.value;
            if (!siteId) return;
            const links = [];
            row.querySelectorAll('.managed-link-row').forEach(function (lr) {
                links.push({
                    anchor: lr.querySelector('.managed-link-anchor')?.value || '',
                    url: lr.querySelector('.managed-link-url')?.value || '',
                });
            });
            sites.push({
                site_id: siteId,
                quantity: row.querySelector('.managed-qty')?.value || 1,
                content_source: row.querySelector('.managed-content-source')?.value || 'buyer',
                content_url: row.querySelector('.managed-content-url')?.value || '',
                content_submission_id: row.querySelector('.managed-library-select')?.value || '',
                writing_brief_notes: row.querySelector('.managed-writer-notes')?.value || '',
                links: links,
            });
        });
        return {
            advertiser_id: advertiserSelect?.value || '',
            managed_external_reference: document.getElementById('managed_external_reference')?.value || '',
            managed_assignee_id: document.getElementById('managed_assignee_id')?.value || '',
            managed_notes: document.getElementById('managed_notes')?.value || '',
            sites: sites,
        };
    }

    function applyQuote(quote) {
        if (!quote) return;
        walletBalance = typeof quote.balance === 'number' ? quote.balance : walletBalance;
        if (balanceLabel && quote.balance_label) balanceLabel.textContent = quote.balance_label;
        if (balanceBox) balanceBox.classList.remove('d-none');
        if (quoteBalanceLabel) quoteBalanceLabel.textContent = quote.balance_label || '—';
        if (quoteTotalLabel) quoteTotalLabel.textContent = quote.total_label || '€0.00';
        if (quoteLines) {
            if (!quote.lines || !quote.lines.length) {
                quoteLines.textContent = 'Choose an advertiser and site to see pricing.';
            } else {
                quoteLines.innerHTML = quote.lines.map(function (line) {
                    const fee = line.writing_fee > 0 ? ' + writing €' + Number(line.writing_fee).toFixed(2) : '';
                    const pack = line.pack ? ' · pack' : '';
                    return '<div class="d-flex justify-content-between gap-2 mb-1"><span>'
                        + (line.site_name || 'Site') + ' ×' + line.quantity + fee + pack
                        + '</span><span>€' + Number(line.line_total).toFixed(2) + '</span></div>';
                }).join('');
            }
        }
        const insufficient = quote.sufficient === false;
        if (balanceWarn) balanceWarn.classList.toggle('d-none', !insufficient);
        if (reviewBtn) reviewBtn.disabled = !!insufficient;
    }

    function scheduleQuote() {
        clearTimeout(quoteTimer);
        quoteTimer = window.setTimeout(fetchQuote, 280);
    }

    function fetchQuote() {
        const payload = collectPayload();
        if (!payload.advertiser_id || !payload.sites.length) {
            if (quoteLines) quoteLines.textContent = 'Choose an advertiser and site to see pricing.';
            if (quoteTotalLabel) quoteTotalLabel.textContent = '€0.00';
            if (reviewBtn) reviewBtn.disabled = false;
            if (balanceWarn) balanceWarn.classList.add('d-none');
            return;
        }
        const incomplete = payload.sites.some(function (s) {
            return !s.links.length || !s.links[0].anchor || !isHttpsUrl(s.links[0].url)
                || (s.content_source === 'buyer' && !isHttpUrl(s.content_url))
                || (s.content_source === 'library' && !s.content_submission_id);
        });
        if (incomplete) return;

        const body = new FormData();
        body.append('_token', csrf);
        body.append('advertiser_id', payload.advertiser_id);
        body.append('managed_external_reference', payload.managed_external_reference);
        payload.sites.forEach(function (site, i) {
            body.append('sites[' + i + '][site_id]', site.site_id);
            body.append('sites[' + i + '][quantity]', site.quantity);
            body.append('sites[' + i + '][content_source]', site.content_source);
            body.append('sites[' + i + '][content_url]', site.content_url);
            body.append('sites[' + i + '][content_submission_id]', site.content_submission_id);
            body.append('sites[' + i + '][writing_brief_notes]', site.writing_brief_notes);
            site.links.forEach(function (link, j) {
                body.append('sites[' + i + '][links][' + j + '][anchor]', link.anchor);
                body.append('sites[' + i + '][links][' + j + '][url]', link.url);
            });
        });

        fetch(quoteUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        }).then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
          .then(function (result) {
              if (result.ok && result.data && result.data.quote) {
                  applyQuote(result.data.quote);
              }
          }).catch(function () {});
    }

    if (advertiserSelect) {
        advertiserSelect.addEventListener('change', function () {
            const data = selectedOptionData(advertiserSelect);
            if (data && (data.balance_label || data['balance-label'])) {
                walletBalance = parseFloat(data.balance || data['balance'] || '0');
                if (balanceLabel) balanceLabel.textContent = data.balance_label || data['balance-label'];
                if (balanceBox) balanceBox.classList.remove('d-none');
                if (quoteBalanceLabel) quoteBalanceLabel.textContent = data.balance_label || data['balance-label'];
            } else if (!advertiserSelect.value) {
                if (balanceBox) balanceBox.classList.add('d-none');
            }
            updateLibrarySearchUrls();
            scheduleQuote();
        });
    }

    document.getElementById('addSiteBtn')?.addEventListener('click', function () {
        addSiteRow();
    });

    form.addEventListener('submit', function (event) {
        let valid = true;
        sitesList.querySelectorAll('.managed-site-row').forEach(function (row) {
            const source = row.querySelector('.managed-content-source')?.value;
            const contentUrl = row.querySelector('.managed-content-url');
            if (source === 'buyer' && contentUrl && !isHttpUrl(contentUrl.value)) {
                contentUrl.classList.add('is-invalid');
                valid = false;
            }
            row.querySelectorAll('.managed-link-url').forEach(function (url) {
                if (!isHttpsUrl(url.value)) {
                    url.classList.add('is-invalid');
                    valid = false;
                }
            });
        });
        if (!valid) {
            event.preventDefault();
            return;
        }
        if (reviewBtn && reviewBtn.disabled) {
            event.preventDefault();
        }
    });

    (Array.isArray(oldSites) && oldSites.length ? oldSites : [{}]).forEach(function (preset) {
        addSiteRow(preset || {});
    });

    // Prefetch advertiser label if old id present.
    if (advertiserSelect && advertiserSelect.value && window.AdminThemeSelects) {
        const url = new URL(advertiserSelect.dataset.adminSelectSearchUrl, window.location.origin);
        url.searchParams.set('selected', advertiserSelect.value);
        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const opt = (data.options || []).find(function (o) { return String(o.value) === String(advertiserSelect.value); });
                if (!opt) return;
                advertiserSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select advertiser…';
                advertiserSelect.appendChild(placeholder);
                const o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                Object.keys(opt.data || {}).forEach(function (k) { o.setAttribute('data-' + k, opt.data[k]); });
                o.selected = true;
                advertiserSelect.appendChild(o);
                advertiserSelect.dispatchEvent(new Event('admin-select-refresh'));
                advertiserSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }).catch(function () {});
    }
})();
</script>
@endpush
