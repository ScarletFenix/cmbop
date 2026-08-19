{{--
    The two facts every listing repeats: link attribute, and typical turnaround.

    @param \App\Models\Site|null $site  Preferred — uses Site label helpers.
    @param string|null $linkType        Fallback when $site is not passed.
    @param string|null $turnaround      Fallback raw turnaround code.
--}}
@php
    $chipLinkType = $site?->linkTypeLabel()
        ?: (trim((string) ($linkType ?? '')) !== ''
            ? (match (strtolower(trim((string) $linkType))) {
                'dofollow' => 'DoFollow',
                'nofollow' => 'NoFollow',
                default => ucfirst(trim((string) $linkType)),
            })
            : null);
    $chipTurnaround = $site?->turnaroundLabel()
        ?: trim((string) ($turnaround ?? ''));
@endphp

<div class="catalog-meta-chips">
    @if($chipLinkType)
    <span class="catalog-meta-chip" title="{{ $chipLinkType }} links on this placement">
        <i class="fa-solid fa-link" aria-hidden="true"></i>
        <span>{{ $chipLinkType }}</span>
    </span>
    @endif
    @if($chipTurnaround !== '')
        <span class="catalog-meta-chip" title="Typical turnaround once the publisher accepts">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $chipTurnaround }}</span>
        </span>
    @endif
</div>
