{{-- Exclusive listing disclosure chip. Expects $site. --}}
@php
    $tagValue = $site->tagValue();
    $tagLabel = $site->tagLabel();
    $tagTitle = \App\Support\SiteTag::catalogChipTitle($tagValue);
    $tagIcon = match ($tagValue) {
        \App\Support\SiteTag::SPONSORED => 'fa-star',
        \App\Support\SiteTag::PARTNER => 'fa-handshake',
        \App\Support\SiteTag::AS_YOU_PREFER => 'fa-sliders-h',
        default => null,
    };
    $tagModifier = match ($tagValue) {
        \App\Support\SiteTag::SPONSORED => 'sponsored',
        \App\Support\SiteTag::PARTNER => 'partner',
        \App\Support\SiteTag::AS_YOU_PREFER => 'prefer',
        default => null,
    };
@endphp
@if($tagValue && $tagLabel && $tagModifier)
    <span class="site-chip site-chip--{{ $tagModifier }}"
          @if($tagTitle) title="{{ $tagTitle }}" @endif>
        @if($tagIcon)
            <i class="fa-solid {{ $tagIcon }}" aria-hidden="true"></i>
        @endif
        <span>{{ $tagLabel }}</span>
    </span>
@endif
