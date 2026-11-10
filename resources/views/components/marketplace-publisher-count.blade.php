@php
    $publisherCountLabel = class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'publisherCountLabel')
        ? \App\Support\HomepageAiVisibility::publisherCountLabel()
        : '1000+ publishers';
@endphp
<p class="text-muted mb-4">The SEOLinkBuildings marketplace has {{ $publisherCountLabel }}.</p>
