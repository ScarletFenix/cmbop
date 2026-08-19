@props(['key'])

@if(\App\Support\FeatureBadge::active($key))
    <span {{ $attributes->class('feature-new-badge') }}>{{ \App\Support\FeatureBadge::label($key) }}</span>
@endif
