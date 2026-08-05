{{--
    Empty-results illustration.

    A crossed-out filter glyph in a circle read as an error. This draws the thing
    the shopper is looking at — a list of listings — with the rows faded out, so
    it says "nothing here yet" rather than "something went wrong".
--}}
<svg class="catalog-empty-art" viewBox="0 0 168 108" role="img"
     aria-label="An empty list of publisher listings" focusable="false">
    <defs>
        <linearGradient id="catalogEmptyFade" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--brand-primary-bg, #e6f5f5)" stop-opacity="0.95" />
            <stop offset="100%" stop-color="var(--brand-primary-bg, #e6f5f5)" stop-opacity="0.15" />
        </linearGradient>
    </defs>

    <rect x="12" y="10" width="144" height="88" rx="10"
          fill="var(--surface-1, #fff)" stroke="var(--border-subtle, #e2e8f0)" stroke-width="2" />
    <rect x="12" y="10" width="144" height="20" rx="10" fill="url(#catalogEmptyFade)" />
    <rect x="12" y="26" width="144" height="4" fill="var(--surface-1, #fff)" />

    {{-- Three placeholder rows: a tile, a domain line and a metric bar each. --}}
    <g fill="var(--border-subtle, #e2e8f0)">
        <rect x="24" y="42" width="16" height="16" rx="4" />
        <rect x="48" y="45" width="52" height="5" rx="2.5" />
        <rect x="48" y="54" width="30" height="4" rx="2" />
        <rect x="116" y="47" width="28" height="5" rx="2.5" />
    </g>
    <g fill="var(--border-subtle, #e2e8f0)" opacity="0.62">
        <rect x="24" y="68" width="16" height="16" rx="4" />
        <rect x="48" y="71" width="44" height="5" rx="2.5" />
        <rect x="48" y="80" width="26" height="4" rx="2" />
        <rect x="116" y="73" width="28" height="5" rx="2.5" />
    </g>

    {{-- The magnifier says "we looked", not "this broke". --}}
    <circle cx="126" cy="86" r="15" fill="var(--surface-1, #fff)" />
    <circle cx="126" cy="86" r="10.5" fill="none"
            stroke="var(--brand-primary, #1a585e)" stroke-width="2.5" />
    <line x1="133.5" y1="93.5" x2="141" y2="101" stroke="var(--brand-primary, #1a585e)"
          stroke-width="2.5" stroke-linecap="round" />
</svg>
