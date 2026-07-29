@php
    /** @var list<array{name: string, url: string}> $items */
    $items = $items ?? [];
    if (count($items) < 2) {
        return;
    }
@endphp
<nav aria-label="Breadcrumb" class="slb-breadcrumbs mb-3">
    <ol class="breadcrumb mb-0 small">
        @foreach($items as $item)
            <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}">
                @if(!$loop->last)
                    <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
                @else
                    {{ $item['name'] }}
                @endif
            </li>
        @endforeach
    </ol>
</nav>
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_values(array_map(static function (array $item, int $index): array {
        return [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'name' => $item['name'],
            'item' => $item['url'],
        ];
    }, $items, array_keys($items))),
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
