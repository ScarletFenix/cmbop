{{--
    The two facts every listing repeats: how many dofollow links, and how fast.

    They were two lines of grey prose under the domain ("Max 03 DoFollow links" /
    "Turnaround: 48h"), which added height to every row and read slowly. As icon
    chips they take one line and scan as data.

    @param string|null $linkType
    @param string|null $turnaround
--}}
@php
    $chipLinkType = trim((string) ($linkType ?? '')) ?: 'DoFollow';
    $chipTurnaround = trim((string) ($turnaround ?? ''));
@endphp

<div class="catalog-meta-chips">
    <span class="catalog-meta-chip" title="Up to 3 {{ $chipLinkType }} links per placement">
        <i class="fa-solid fa-link" aria-hidden="true"></i>
        <span>3 {{ $chipLinkType }}</span>
    </span>
    @if($chipTurnaround !== '')
        <span class="catalog-meta-chip" title="Typical turnaround once the publisher accepts">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $chipTurnaround }}</span>
        </span>
    @endif
</div>
