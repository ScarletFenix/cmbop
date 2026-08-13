@php
    $bulkWaitingItems = collect($bulkWaitingItems ?? []);
    $waitingItemsCount = (int) ($waitingItemsCount ?? $bulkWaitingItems->count());
    $hasOpenBulkRequest = ! empty($openBulkRequest);
    $hasTableRows = $sites->count() > 0 || $bulkWaitingItems->isNotEmpty();
    $inviteCount = (int) ($inviteCount ?? 0);
@endphp
<div id="sitesStatusMeta"
     data-pending="{{ (int) ($pendingCount ?? 0) }}"
     data-active="{{ (int) ($activeCount ?? 0) }}"
     data-invites="{{ $inviteCount }}"
     data-active-ids="{{ implode(',', $activeIds ?? []) }}"
     data-status="{{ $status ?? 'active' }}"
     data-bulk-waiting="{{ $waitingItemsCount }}"
     data-open-bulk="{{ $hasOpenBulkRequest ? '1' : '0' }}"
     class="d-none"
     aria-hidden="true"></div>
@if($hasTableRows)
<style>
    .modern-table {
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        text-align: left;
        margin-bottom: 0;
        background: #fff;
        width: 100%;
        table-layout: fixed;
        min-width: 0;
        border-collapse: separate;
        border-spacing: 0;
    }

    .modern-table th, .modern-table td {
        vertical-align: middle !important;
    }

    /* Keep short columns tight; Site / Actions may wrap without stretching the page. */
    .modern-table thead th,
    .modern-table td[data-label="Metrics"],
    .modern-table td[data-label="Market"],
    .modern-table td[data-label="Status"],
    .modern-table td[data-label="Price"] {
        white-space: nowrap;
    }

    .modern-table col.col-preview { width: 148px; }
    .modern-table col.col-site { width: auto; }
    .modern-table col.col-metrics { width: 188px; }
    .modern-table col.col-market { width: 128px; }
    .modern-table col.col-status { width: 118px; }
    .modern-table col.col-price { width: 148px; }
    .modern-table col.col-actions { width: 280px; }

    .sites-table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        max-width: 100%;
    }

    .modern-table thead {
        background: var(--brand-primary, #1a585e);
        color: #fff;
        text-align: left;
    }

    .modern-table thead th {
        font-size: 12px;
        font-weight: 650;
        letter-spacing: .02em;
        padding: 14px 16px;
        border: 0;
    }

    .modern-table thead th:first-child,
    .modern-table tbody tr.main-row td:first-child {
        padding-left: 18px;
    }

    /* Keep metrics (esp. traffic) from visually colliding into Market. */
    .modern-table td[data-label="Market"] {
        padding-left: 14px;
    }

    .modern-table thead th:last-child,
    .modern-table tbody tr.main-row td:last-child {
        padding-right: 18px;
    }

    /* Column alignment: Site left; Preview / Metrics / Market / Status / Price / Actions centered.
       Use !important so AJAX-injected styles beat page-level th/td left rules. */
    .modern-table thead th:nth-child(1),
    .modern-table thead th:nth-child(3),
    .modern-table thead th:nth-child(4),
    .modern-table thead th:nth-child(5),
    .modern-table thead th:nth-child(6),
    .modern-table thead th:nth-child(7),
    .modern-table tbody tr.main-row td[data-label="Preview"],
    .modern-table tbody tr.main-row td[data-label="Metrics"],
    .modern-table tbody tr.main-row td[data-label="Market"],
    .modern-table tbody tr.main-row td[data-label="Status"],
    .modern-table tbody tr.main-row td[data-label="Price"],
    .modern-table tbody tr.main-row td[data-label="Actions"] {
        text-align: center !important;
    }

    .modern-table thead th:nth-child(2),
    .modern-table tbody tr.main-row td[data-label="Site"] {
        text-align: left !important;
    }

    .site-row-price-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        width: 100%;
        max-width: 100%;
        margin-inline: auto;
        box-sizing: border-box;
    }

    .modern-table tbody tr.main-row {
        cursor: default;
        transition: background 0.15s ease;
    }

    .modern-table tbody tr.main-row:hover {
        background: #f7fafb;
    }

    .modern-table tbody tr.main-row td {
        padding: 14px 16px;
        border-color: #eef2f5;
        vertical-align: middle !important;
    }

    /*
     * Desktop 16:10 frame via padding-top (Safari-safe). Hover zoom still
     * uses the floating popover for a larger desktop read of the screenshot.
     */
    .site-row-preview {
        position: relative;
        display: inline-block;
        width: 136px;
        max-width: 100%;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: linear-gradient(145deg, #f8fafb 0%, #eef2f5 100%);
        flex-shrink: 0;
        cursor: zoom-in;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4);
        vertical-align: middle;
    }

    .site-row-preview::before {
        content: '';
        display: block;
        width: 100%;
        padding-top: 62.5%; /* 10 / 16 */
    }

    .site-row-preview:hover,
    .site-row-preview:focus-visible {
        border-color: var(--brand-primary, #1a585e);
        box-shadow: 0 0 0 1px var(--brand-primary, #1a585e);
        outline: none;
    }

    .site-row-preview img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        max-width: none;
        object-fit: contain;
        object-position: center top;
        display: block;
        background: #f8fafc;
    }

    .site-row-preview.is-empty {
        color: #94a3b8;
        font-size: 18px;
        cursor: default;
    }

    .site-row-preview.is-empty > i,
    .site-row-preview.is-empty > span {
        position: absolute;
        inset: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .site-row-identity {
        min-width: 0;
        max-width: 100%;
        white-space: normal;
        overflow: hidden;
    }

    .site-row-name {
        font-weight: 650;
        color: var(--brand-primary, #1a585e);
        margin: 0 0 2px;
        line-height: 1.25;
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
    }

    .site-row-name-text {
        min-width: 0;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .sites-row-new-badge {
        display: none;
        flex-shrink: 0;
        min-width: 1.35rem;
        padding: 0.18em 0.45em;
        font-size: 0.65rem;
        font-weight: 700;
        line-height: 1;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: #fff !important;
        background: #dc2626 !important;
        border: 1px solid #b91c1c;
        border-radius: 999px;
        vertical-align: middle;
    }

    .sites-row-new-badge.is-visible {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .site-row-url {
        font-size: 12px;
        color: var(--brand-ink-muted, #75787B);
        margin: 0;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-all;
    }

    .site-row-category {
        display: inline-block;
        margin-top: 3px;
        max-width: 100%;
        font-size: 11px;
        font-weight: 600;
        color: #475569;
        background: #f1f5f9;
        border-radius: 4px;
        padding: 1px 6px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }

    .site-row-metrics {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-wrap: nowrap;
        gap: 8px;
        max-width: 100%;
        font-size: 12px;
        color: #475569;
        box-sizing: border-box;
    }

    .site-row-metrics > span {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .site-row-metrics strong {
        color: var(--brand-primary, #1a585e);
        font-weight: 700;
    }

    .modern-table td[data-label="Metrics"] {
        overflow: hidden;
    }

    .site-row-market {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 12px;
        color: var(--brand-ink-muted, #75787B);
        max-width: 100%;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .site-row-market > span:last-child {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .site-row-market .country-flag {
        flex: 0 0 auto;
        font-size: 16px;
        line-height: 1;
        margin-right: 2px;
    }

    .site-row-actions {
        display: flex;
        flex-direction: column;
        flex-wrap: nowrap;
        align-items: center;
        gap: 6px;
        justify-content: center;
        max-width: 100%;
    }

    .site-row-actions__manage,
    .site-row-actions__offers {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 6px;
        max-width: 100%;
    }

    .site-row-actions__offers-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #94a3b8;
        line-height: 1;
    }

    .site-offer-chips {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    .site-offer-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 28px;
        padding: 0 8px;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #475569;
        font-size: 11.5px;
        font-weight: 600;
        line-height: 1;
        white-space: nowrap;
    }

    .site-offer-chip:hover {
        background: #f8fafc;
        color: #334155;
        border-color: #94a3b8;
    }

    .site-offer-chip.is-on {
        color: var(--brand-primary, #1a585e);
        background: #e6f5f5;
        border-color: rgba(26, 88, 94, 0.28);
    }

    .site-offer-chip.is-on:hover {
        background: #d8ebeb;
        color: var(--brand-primary, #1a585e);
    }

    .site-row-price-advertiser {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #0f766e;
        line-height: 1.25;
        white-space: normal;
        max-width: 11.5rem;
    }

    .site-row-price-advertiser--pack {
        color: #334155;
        font-weight: 500;
    }

    .site-row-price-advertiser__cut {
        color: #b91c1c;
    }

    .modern-table td[data-label="Price"] .site-row-price-wrap {
        white-space: normal;
    }

    .site-row-actions .btn-edit {
        margin-left: 2px;
        margin-right: 0;
        padding: 0.25rem 0.85rem;
        font-size: 12.5px;
        line-height: 1.2;
        border-radius: 999px;
    }

    .site-row-actions .btn-verify-site {
        margin-left: 0;
        margin-right: 0;
        padding: 0.25rem 0.7rem;
        font-size: 12px;
        line-height: 1.2;
        border-radius: 999px;
        white-space: nowrap;
        border-color: var(--brand-primary, #1a585e);
        color: var(--brand-primary, #1a585e);
    }

    .site-row-actions .btn-verify-site:hover {
        background: var(--brand-primary, #1a585e);
        color: #fff;
    }

    .site-row-actions .btn-icon-quiet {
        width: 34px;
        height: 34px;
    }

    .site-row-actions .btn-icon-quiet.is-on {
        color: var(--brand-primary, #1a585e);
        background: #e6f5f5;
    }

    .site-row-actions .btn-text-quiet {
        border: 0;
        background: transparent;
        color: var(--brand-ink-muted, #75787B);
        font-size: 12px;
        font-weight: 600;
        padding: 0.25rem 0.5rem;
        border-radius: 999px;
        transition: background-color 150ms ease, color 150ms ease;
    }

    .site-row-actions .btn-text-quiet:hover {
        background: rgba(15, 23, 42, 0.06);
        color: #334155;
    }

    .site-row-actions .btn-text-quiet.is-danger:hover {
        background: #fef2f2;
        color: #dc2626;
    }

    .expand-row {
        background: #fafafa;
        transition: all 0.3s ease-in-out;
    }

    .expand-row td {
        padding: 0 !important;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
        white-space: normal !important;
    }

    .expand-box {
        padding: 0 18px;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
    }

    .expand-row.expanded .expand-box {
        padding: 18px;
        max-height: 800px;
        opacity: 1;
    }

    .detail-line {
        margin-bottom: 8px;
        font-size: 14px;
    }

    .detail-line strong {
        color: #555;
        margin-right: 5px;
    }

    .tag-badge {
        background: #eef6ff;
        color: var(--brand-primary, #1a585e);
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 12px;
        margin-right: 6px;
        display: inline-block;
    }

    .sensitive-badge {
        background: #fff3cd;
        color: #856404;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 12px;
        margin-right: 6px;
        display: inline-block;
    }

    .desc-box {
        margin-top: 10px;
        padding: 10px;
        background: #fff;
        border: 1px solid #eee;
        border-radius: 8px;
    }

    .turnaround-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        background-color: #f1f1f1;
        color: #282828;
    }

    .status-badge {
        font-size: 11px;
        font-weight: 650;
    }

    /* Readable status chips (avoid Bootstrap bg-info white-on-white) */
    .site-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        font-weight: 650;
        letter-spacing: .01em;
        border-radius: 999px;
        padding: 4px 10px;
        border: 1px solid transparent;
        line-height: 1.2;
        white-space: nowrap;
    }
    .site-status--verified {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
    }
    .site-status--active {
        background: #e6f5f5;
        color: #123f42;
        border-color: #b8e4e4;
    }
    .site-status--pending {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }
    .site-status--with-marketer {
        background: #fff7ed;
        color: #9a3412;
        border-color: #fed7aa;
    }
    .site-status--invite {
        background: #eff6ff;
        color: #1e40af;
        border-color: #bfdbfe;
    }
    .site-status--needs-details {
        background: #ecfeff;
        color: #155e75;
        border-color: #a5f3fc;
    }
    .site-status--ready-review {
        background: #e6f5f5;
        color: #123f42;
        border-color: #b8e4e4;
    }
    .site-status--with-admin {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }
    .site-status-stack {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
    }
    .site-status-stack a.site-status {
        text-decoration: none;
    }
    .site-status-stack a.site-status:hover {
        filter: brightness(0.97);
    }
    .bulk-waiting-row td {
        background: #fffaf5;
    }

    .site-row-price {
        display: inline-block;
        font-weight: 700;
        color: var(--brand-primary, #1a585e);
        white-space: nowrap;
        text-align: center;
    }

    .site-row-price-meta {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px;
        margin-left: 0;
        vertical-align: middle;
    }

    @media (max-width: 768px) {
        .site-row-actions,
        .site-row-actions__manage,
        .site-row-actions__offers {
            align-items: flex-end;
            justify-content: flex-end;
        }

        .site-status-stack {
            align-items: flex-end;
        }

        .site-row-metrics,
        .site-row-market,
        .site-row-price-meta {
            justify-content: flex-end;
        }
    }
</style>

{{-- Floating hover zoom for row screenshot thumbs (avoids opening a new tab) --}}
<style>
    .site-preview-zoom-pop {
        position: fixed;
        z-index: 1200;
        width: min(440px, calc(100vw - 24px));
        max-height: min(300px, calc(100vh - 24px));
        padding: 6px;
        border-radius: 12px;
        border: 1px solid rgba(26, 88, 94, 0.18);
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(14px) saturate(1.2);
        -webkit-backdrop-filter: blur(14px) saturate(1.2);
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        pointer-events: none;
        opacity: 0;
        transform: translateY(4px) scale(0.98);
        transition: opacity .16s ease, transform .16s ease;
        overflow: hidden;
    }
    .site-preview-zoom-pop.is-visible {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
    .site-preview-zoom-pop::before {
        content: '';
        display: block;
        width: 100%;
        padding-top: 62.5%;
    }
    .site-preview-zoom-pop img {
        position: absolute;
        inset: 6px;
        width: calc(100% - 12px);
        height: calc(100% - 12px);
        max-width: none;
        object-fit: contain;
        object-position: center top;
        border-radius: 8px;
        background: #f8fafc;
        display: block;
    }
    @media (hover: none) {
        .site-preview-zoom-pop { display: none !important; }
        .site-row-preview { cursor: default; }
    }
    @media (prefers-reduced-motion: reduce) {
        .site-preview-zoom-pop {
            transition: none;
        }
    }
</style>

@php
    if (!function_exists('getCountryFlag')) {
        function getCountryFlag($countryCode) {
            $code = strtoupper(trim((string) $countryCode));
            if (strlen($code) !== 2) {
                return '';
            }
            if ($code === 'UK') {
                $code = 'GB';
            }

            return mb_chr(127397 + ord($code[0]), 'UTF-8').mb_chr(127397 + ord($code[1]), 'UTF-8');
        }
    }

    if (!function_exists('getLanguageName')) {
        function getLanguageName($code) {
            return fullLanguage($code);
        }
    }

    if (!function_exists('getPublicationDuration')) {
        function getPublicationDuration($value) {
            $durations = [
                '6months' => '6 Months',
                '1year' => '1 Year',
                'permanent' => 'Permanent'
            ];
            return $durations[$value] ?? ucfirst($value);
        }
    }

    if (!function_exists('getTurnaroundLabel')) {
        function getTurnaroundLabel($value) {
            $labels = [
                '24h' => '24 Hours',
                '48h' => '48 Hours',
                '3days' => '3 Days',
                '5days' => '5 Days',
                '7days' => '7 Days'
            ];
            return $labels[$value] ?? '3 Days';
        }
    }

@if($sites->count() > 0)
<style>
    /* overflow:visible — overflow:hidden clipped Status/Price/Actions when Category grew wide */
    .modern-table { border-radius: 12px; overflow: visible; border: 1px solid #eee; text-align: center; border-collapse: separate; border-spacing: 0; }
    .modern-table th, .modern-table td { vertical-align: middle !important; }
    .modern-table thead { background: #343a40; color: #fff; text-align: center; }
    .modern-table thead th { background: #343a40; color: #fff; font-weight: 600; }
    .modern-table tbody tr:hover { background: #f7fbff; }
    .expand-row td { padding: 0 !important; overflow: hidden; }
    .expand-box { padding: 0 18px; max-height: 0; opacity: 0; overflow: hidden; transition: all 0.3s ease-in-out; }
    .expand-row.expanded .expand-box { padding: 18px; max-height: 800px; opacity: 1; }
    .detail-line { margin-bottom: 8px; font-size: 14px; }
    .tag-badge { background: #eef6ff; color: #0b6266; padding: 5px 10px; border-radius: 6px; font-size: 12px; margin-right: 6px; display: inline-block; }
    .sensitive-badge { background: #fff3cd; color: #856404; padding: 5px 10px; border-radius: 6px; font-size: 12px; margin-right: 6px; display: inline-block; }
    .desc-box { margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #eee; border-radius: 8px; }
    .category-chip { display: inline-block; background: #eef7f7; color: #0b6266; border-radius: 6px; padding: 2px 8px; font-size: 11px; margin: 1px; max-width: 100%; white-space: normal; }
    .pending-meta { font-size: 11px; color: #64748b; margin-top: 4px; }
    .turnaround-badge { display: inline-block; padding: 5px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; background-color: #f1f1f1; color: #282828; }
</style>

<div class="table-responsive sites-table-scroll">
<table class="table modern-table sites-responsive-table align-middle mb-0">
    <colgroup>
        <col class="col-preview">
        <col class="col-site">
        <col class="col-metrics">
        <col class="col-market">
        <col class="col-status">
        <col class="col-price">
        <col class="col-actions">
    </colgroup>
    <thead>
        <tr>
            <th scope="col" class="text-center">Preview</th>
            <th scope="col" class="text-start">Site</th>
            <th scope="col" class="text-center">Metrics</th>
            <th scope="col" class="text-center">Market</th>
            <th scope="col" class="text-center">Status</th>
            <th scope="col" class="text-center">Price</th>
            <th scope="col" class="text-center">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($bulkWaitingItems as $item)
        <tr class="main-row bulk-waiting-row" data-bulk-item-id="{{ $item->id }}">
            <td data-label="Preview" class="text-center">
                <span class="site-row-preview is-empty"
                      data-glass-tip
                      data-glass-tip-body="Waiting on marketer"
                      data-glass-tip-placement="top"
                      data-glass-tip-hover-only="1"
                      aria-label="Waiting on marketer">
                    <i class="fa fa-hourglass-half" aria-hidden="true"></i>
                </span>
            </td>
            <td data-label="Site" class="text-start">
                <div class="site-row-identity">
                    <p class="site-row-name">
                        <span class="site-row-name-text"
                              data-glass-tip
                              data-glass-tip-body="{{ $item->domain ?: $item->site_url }}"
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">{{ $item->domain ?: $item->site_url }}</span>
                    </p>
                    <p class="site-row-url"
                       data-glass-tip
                       data-glass-tip-body="{{ $item->site_url }}"
                       data-glass-tip-placement="top"
                       data-glass-tip-hover-only="1">{{ $item->site_url }}</p>
                </div>
            </td>
            <td data-label="Metrics" class="text-center">
                <div class="site-row-metrics text-muted">
                    <span>—</span>
                </div>
            </td>
            <td data-label="Market" class="text-center">
                <span class="text-muted">—</span>
            </td>
            <td data-label="Status" class="text-center">
                <span class="site-status site-status--with-marketer"
                      data-glass-tip
                      data-glass-tip-body="Our marketer is preparing DA/DR, traffic, language, country, and niches for this URL."
                      data-glass-tip-placement="top"
                      data-glass-tip-hover-only="1">
                    <i class="fa-solid fa-user-pen" aria-hidden="true"></i>With marketer
                </span>
            </td>
            <td data-label="Price" class="text-center">
                <div class="site-row-price-wrap">
                    <span class="site-row-price">€{{ number_format((float) $item->price, 2) }}</span>
                </div>
            </td>
            <td data-label="Actions" class="text-center">
                <span class="small text-muted">No edit yet</span>
            </td>
        </tr>
        @endforeach
        @foreach($sites as $index => $site)
        @php
            // Cover first (admin parity), then screenshots. /media → /storage chain.
            $previewPaths = $site->listingPreviewUrlChain();
            $previewUrl = $previewPaths[0] ?? null;
            $zoomPaths = $site->zoomPreviewUrlChain();
            if ($zoomPaths === [] && $previewPaths !== []) {
                $zoomPaths = $previewPaths;
            }
            $fullPreviewUrl = $zoomPaths[0] ?? null;
            $siteCountries = is_array($site->countries) && count($site->countries)
                ? $site->countries
                : array_filter([$site->country]);
            $siteLanguages = is_array($site->languages) && count($site->languages)
                ? $site->languages
                : array_filter([$site->language]);
            $categoryLabel = is_array($site->categories) && count($site->categories)
                ? implode(', ', array_slice($site->categories, 0, 2))
                : (string) $site->category;
        @endphp
        <tr class="main-row" data-id="{{ $site->id }}">
            <td data-label="Preview" class="text-center">
                @if($previewUrl)
                    <span class="site-row-preview"
                          role="img"
                          tabindex="0"
                          aria-label="{{ $site->site_name }} preview"
                          data-zoom-src="{{ $fullPreviewUrl ?: $previewUrl }}"
                          data-zoom-chain="{{ json_encode($zoomPaths !== [] ? $zoomPaths : $previewPaths, JSON_UNESCAPED_SLASHES) }}">
                        <img src="{{ $previewUrl }}"
                             alt="{{ $site->site_name }} preview"
                             loading="lazy"
                             decoding="async"
                             data-preview-chain="{{ json_encode($previewPaths, JSON_UNESCAPED_SLASHES) }}"
                             data-preview-i="0"
                             onerror="if(window.publisherSitePreviewOnError){window.publisherSitePreviewOnError(this);}else{(function(img){var c=[];try{c=JSON.parse(img.getAttribute('data-preview-chain')||'[]');}catch(e){c=[];}if(!Array.isArray(c))c=[];var i=parseInt(img.getAttribute('data-preview-i')||'0',10)||0;var n=i+1;if(n&lt;c.length&amp;&amp;c[n]){img.setAttribute('data-preview-i',String(n));img.src=c[n];return;}img.onerror=null;img.removeAttribute('src');var w=img.closest('.site-row-preview');if(w){w.classList.add('is-empty');w.removeAttribute('data-zoom-src');w.removeAttribute('data-zoom-chain');w.innerHTML='<i class=\'fa fa-image\' aria-hidden=\'true\'></i>';}})(this);}">
                    </span>
                @else
                    <span class="site-row-preview is-empty"
                          data-glass-tip
                          data-glass-tip-body="No preview"
                          data-glass-tip-placement="top"
                          data-glass-tip-hover-only="1"
                          aria-label="No preview">
                        <i class="fa fa-image" aria-hidden="true"></i>
                    </span>
                @endif
            </td>

            <td data-label="Site" class="text-start">
                <div class="site-row-identity">
                    <p class="site-row-name">
                        <span class="site-row-name-text"
                              data-glass-tip
                              data-glass-tip-body="{{ $site->site_name }}"
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">{{ $site->site_name }}</span>
                        @if($site->active || $site->verified)
                            <span class="sites-row-new-badge pulse-badge"
                                  data-site-new-badge
                                  hidden
                                  aria-label="Newly approved">New</span>
                        @endif
                    </p>
                    <p class="site-row-url"
                       data-glass-tip
                       data-glass-tip-body="{{ $site->site_url }}"
                       data-glass-tip-placement="top"
                       data-glass-tip-hover-only="1">{{ $site->domain ?: $site->site_url }}</p>
                    @if($categoryLabel !== '')
                        <span class="site-row-category"
                              data-glass-tip
                              data-glass-tip-body="{{ $categoryLabel }}"
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">{{ $categoryLabel }}</span>
                    @endif
                </div>
            </td>

            <td data-label="Metrics" class="text-center">
                <div class="site-row-metrics"
                     data-glass-tip
                     data-glass-tip-title="Metrics"
                     data-glass-tip-body="DA {{ (int) $site->da }} · DR {{ (int) $site->dr }} · Traffic {{ number_format((int) $site->traffic) }}"
                     data-glass-tip-placement="top"
                     data-glass-tip-hover-only="1">
                    <span>DA <strong>{{ $site->da }}</strong></span>
                    <span>DR <strong>{{ $site->dr }}</strong></span>
                    <span title="Traffic {{ number_format((int) $site->traffic) }}">Tr <strong>{{ $site->formattedTraffic() }}</strong></span>
                </div>
            </td>

            <td data-label="Market" class="text-center">
                <div class="site-row-market">
                    <span class="country-flag" aria-hidden="true">
                        @foreach(array_slice($siteCountries, 0, 2) as $code)
                            {!! getCountryFlag($code) !!}
                        @endforeach
                    </span>
                    <span>{{ collect(array_slice($siteLanguages, 0, 2))->map(fn ($c) => getLanguageName($c))->implode(', ') }}</span>
                </div>
            </td>

            <td data-label="Status" class="text-center">
                @php $isArchived = $site->isArchived(); @endphp
                @if($isArchived)
                    <span class="badge bg-dark status-badge" title="Archived — hidden from catalog">
                        <i class="fa fa-box-archive me-1"></i>Archived
                    </span>
                @elseif(($status ?? '') === 'invites' || $site->isPendingPublisherAcceptance())
                    <span class="site-status site-status--invite"
                          data-glass-tip
                          data-glass-tip-body="Our team added this listing. Accept it to show it in My Sites."
                          data-glass-tip-placement="top"
                          data-glass-tip-hover-only="1">
                        <i class="fa-solid fa-inbox" aria-hidden="true"></i>Invite
                    </span>
                @elseif($site->verified)
                    <span class="site-status site-status--verified"
                          data-glass-tip
                          data-glass-tip-body="Verified"
                          data-glass-tip-placement="top"
                          data-glass-tip-hover-only="1">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified
                    </span>
                @elseif($site->active)
                    <span class="site-status site-status--active"
                          data-glass-tip
                          data-glass-tip-body="Active"
                          data-glass-tip-placement="top"
                          data-glass-tip-hover-only="1">
                        <i class="fa-solid fa-circle-play" aria-hidden="true"></i>Active
                    </span>
                @elseif(($status ?? 'active') === 'pending')
                    <div class="site-status-stack">
                        @if($site->awaitsPublisherDetails())
                            <a href="{{ route('publisher.bulk-sites.complete') }}"
                               class="site-status site-status--needs-details"
                               data-glass-tip
                               data-glass-tip-body="Add description and listing details, then continue to Review &amp; submit."
                               data-glass-tip-placement="top"
                               data-glass-tip-hover-only="1">
                                <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Needs your details
                            </a>
                        @elseif($site->hasDetailsComplete())
                            <a href="{{ route('publisher.bulk-sites.review') }}"
                               class="site-status site-status--ready-review"
                               data-glass-tip
                               data-glass-tip-body="Details saved — open Review &amp; submit to send this site to admin."
                               data-glass-tip-placement="top"
                               data-glass-tip-hover-only="1">
                                <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>Ready to review
                            </a>
                        @else
                            <span class="site-status site-status--with-admin"
                                  data-glass-tip
                                  data-glass-tip-body="Submitted — waiting for admin approval."
                                  data-glass-tip-placement="top"
                                  data-glass-tip-hover-only="1">
                                <i class="fa-regular fa-clock" aria-hidden="true"></i>With admin
                            </span>
                        @endif
                    </div>
                </td>
                <td data-label="Status">
                    @if($isArchived)
                        <span class="badge bg-dark status-badge" title="Archived — hidden from catalog">
                            <i class="fa fa-box-archive me-1"></i>Archived
                        </span>
                    @elseif(($status ?? '') === 'invites' || $site->isPendingPublisherAcceptance())
                        <span class="badge bg-primary status-badge" title="Staff added this listing — Accept to move it into My Sites">
                            <i class="fa-solid fa-inbox me-1"></i>Invite
                        </span>
                    @elseif($site->verified && $site->active)
                        <span class="badge bg-success status-badge" title="Verified and live in catalog">
                            <i class="fa-solid fa-circle-check me-1"></i>Verified · live
                        </span>
                    @elseif($site->verified && ! $site->active)
                        <span class="badge bg-secondary status-badge" title="Verified but inactive">
                            <i class="fa-solid fa-circle-pause me-1"></i>Verified · inactive
                        </span>
                    @elseif($site->active)
                        <span class="badge bg-info status-badge" title="Active in catalog but not verified">
                            <i class="fa-solid fa-circle-play me-1"></i>Active
                        </span>
                    @else
                        <span class="badge bg-secondary status-badge" title="Pending admin review">
                            <i class="fa-regular fa-clock me-1"></i>Pending
                        </span>
                        <div class="pending-meta">
                            Submitted {{ $site->created_at?->diffForHumans() }}
                            <div>Usually reviewed within 24–48 hours</div>
                            @if($site->updated_at && $site->updated_at->ne($site->created_at))
                                <div>Updated {{ $site->updated_at->diffForHumans() }}</div>
                            @endif
                        </div>
                    @endif
                </td>
                <td data-label="Price">
                    €{{ number_format($site->price, 2) }}
                    @if($site->isFeatured())
                        <span class="badge bg-warning text-dark"
                              data-glass-tip
                              data-glass-tip-title="Featured"
                              data-glass-tip-body="Featured in the advertiser catalog{{ $featureDaysLeft ? ' · '.$featureDaysLeft.' day'.($featureDaysLeft === 1 ? '' : 's').' left' : '' }}."
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">★{{ $featureDaysLeft ? ' '.$featureDaysLeft.'d' : '' }}</span>
                    @endif
                    @if($pubShowSaleBadge)
                        <span class="badge bg-danger"
                              data-glass-tip
                              data-glass-tip-title="Timed sale −{{ $fmtPct($pubCustomPct) }}% (configured)"
                              data-glass-tip-body="{{ $pubAdvTip ?: 'Your timed discount is live on this site.' }}"
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">−{{ $fmtPct($pubCustomPct) }}%</span>
                    @endif
                    @if($pubJoinedBulk)
                        <span class="badge bg-success"
                              data-glass-tip
                              data-glass-tip-title="Bulk −{{ $fmtPct($pubBulkPct) }}% on {{ $bulkMinQty }}–{{ $bulkMaxQty }} articles"
                              data-glass-tip-body="{{ $pubBulkTip }}"
                              data-glass-tip-placement="top"
                              data-glass-tip-hover-only="1">Bulk −{{ $fmtPct($pubBulkPct) }}%</span>
                    @endif
                </td>
                <td data-label="Actions">
                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                        @if(($status ?? '') === 'invites' || $site->isPendingPublisherAcceptance())
                            <button type="button" class="btn btn-sm btn-primary btn-accept-assignment"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    aria-label="Accept">
                                Accept
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-reject-assignment"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    aria-label="Decline">
                                Decline
                            </button>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-primary action-view" data-id="{{ $site->id }}">
                                <i class="fa fa-eye me-1"></i><span class="btn-text">View</span>
                            </button>

                            @unless($isArchived)
                                <button type="button" class="btn btn-sm btn-primary btn-edit" data-id="{{ $site->id }}">Edit</button>
                            @endunless

                            @if(($site->active || $site->verified) && ! $isArchived)
                                <button type="button" class="btn btn-sm btn-warning btn-feature-site"
                                        data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">
                                    <i class="fa fa-bolt"></i> Feature
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success btn-discount-site"
                                        data-id="{{ $site->id }}"
                                        data-name="{{ $site->site_name }}"
                                        data-percent="{{ $site->custom_discount_percent }}"
                                        data-ends="{{ optional($site->custom_discount_ends_at)?->toIso8601String() }}">
                                    <i class="fa fa-percent"></i> Discount
                                </button>
                                @if($site->hasActiveCustomDiscount())
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-discount-clear" data-id="{{ $site->id }}">Clear</button>
                                @endif
                                @if($site->joinsBulkDiscount())
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-bulk-leave" data-id="{{ $site->id }}">Leave bulk</button>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-success btn-bulk-join" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Join bulk</button>
                                @endif
                            @endif

                            @if(! $site->verified && ! $site->active && ! $isArchived)
                                <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" class="delete-form d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="btn btn-sm btn-danger btn-delete">Delete</button>
                                </form>
                            @endif

                            @if(($site->verified || $site->active) && ! $isArchived)
                                <button type="button" class="btn btn-sm btn-outline-dark btn-archive-site" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">
                                    Archive
                                </button>
                            @endif

                            @if($isArchived)
                                <button type="button" class="btn btn-sm btn-outline-primary btn-unarchive-site" data-id="{{ $site->id }}">
                                    Restore
                                </button>
                            @endif
                        @endif
                    </div>

                    @if($site->sensitive_prices)
                        <div class="detail-line">
                            <strong>Sensitive Topics:</strong>
                            @php
                                $prices = is_array($site->sensitive_prices)
                                    ? $site->sensitive_prices
                                    : (is_string($site->sensitive_prices) ? json_decode($site->sensitive_prices, true) : []);
                            @endphp
                            @foreach($prices as $key => $value)
                                <span class="sensitive-badge">{{ ucfirst($key) }}: €{{ number_format($value, 2) }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if($site->offersHomepagePlacement())
                        <div class="detail-line">
                            <strong>Homepage placement:</strong>
                            @foreach($site->homepagePlacementOptions() as $days => $fee)
                                <span class="sensitive-badge">
                                    {{ $days }} day{{ $days > 1 ? 's' : '' }}:
                                    {{ (float) $fee <= 0 ? 'Free' : '€'.number_format((float) $fee, 2) }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if($site->offersSocialPromotion())
                        <div class="detail-line">
                            <strong>Social sharing:</strong>
                            @foreach($site->enabledSocialChannels() as $channel)
                                <span class="tag-badge">{{ $channel === 'x' ? 'X' : ucfirst($channel) }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="desc-box">
                        <strong>Description:</strong>
                        <div>{!! $site->safeDescriptionHtml() !!}</div>
                    </div>
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

@if($sites->hasPages())
<div class="d-flex justify-content-center mt-3">
    {{ $sites->links() }}
</div>
@endif

@else
    <div class="dash-panel text-center py-4">
        @if(($status ?? '') === 'invites')
            <p class="mb-2 fw-semibold"><i class="fa fa-inbox me-2 text-muted"></i>No site invites waiting</p>
            <p class="text-muted small mb-0">When our team adds a website for you, Accept / Decline appear here.</p>
        @else
            <p class="mb-2 fw-semibold">No websites match this filter</p>
            <p class="text-muted small mb-3">Try another status filter or add a new site.</p>
            <button type="button" class="btn btn-primary btn-sm" id="emptyAddSiteCta"><i class="fa fa-plus"></i> Add New Website</button>
        @endif
    </div>
@endif
