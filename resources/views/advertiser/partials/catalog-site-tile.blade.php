{{--
    Monogram tile for a listing.

    Rows were a wall of monospace domains with nothing to fix the eye on. The
    initials come from the label already on screen — for a masked listing that is
    the masked label, so this never discloses a host the row is hiding.

    @param string $label The displayed host (may be masked)
    @param string $size  md (table) | lg (card)
--}}
@php
    $tileLabel = (string) ($label ?? '');
    $tileSize = ($size ?? 'md') === 'lg' ? 'lg' : 'md';

    // First domain segment, split on separators and masking characters.
    $firstSegment = explode('.', $tileLabel)[0] ?? '';
    $words = array_values(array_filter(
        preg_split('/[^A-Za-z0-9]+/', $firstSegment) ?: [],
        fn ($part) => $part !== ''
    ));

    if (count($words) >= 2) {
        $initials = strtoupper(substr($words[0], 0, 1).substr($words[1], 0, 1));
    } elseif (count($words) === 1) {
        $initials = strtoupper(substr($words[0], 0, 2));
    } else {
        $initials = '—';
    }

    // Stable per listing so the same site keeps the same colour between pages.
    $tileTone = (crc32(strtolower($tileLabel)) % 6) + 1;
@endphp

<span class="catalog-tile catalog-tile--{{ $tileSize }} catalog-tile--tone{{ $tileTone }}"
      aria-hidden="true">{{ $initials }}</span>
