@props([
    'title' => null,
    'body' => '',
    'label' => 'More information',
    'placement' => 'top',
    'size' => 'md', // md = 16px, lg = 18px
])

@php
    $classes = 'glass-tip-trigger' . ($size === 'lg' ? ' glass-tip-trigger--lg' : '');
@endphp

<button
    type="button"
    {{ $attributes->class($classes) }}
    data-glass-tip
    @if($title) data-glass-tip-title="{{ $title }}" @endif
    data-glass-tip-body="{{ $body }}"
    data-glass-tip-placement="{{ $placement }}"
    aria-label="{{ $label }}"
>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="118 4 72 244"
        fill="currentColor"
        class="glass-tip-icon"
        aria-hidden="true"
        focusable="false"
    >
        <g transform="translate(1.4065934065934016 1.4065934065934016) scale(2.81 2.81)">
            <path d="M 49.083 71.489 l 5.776 -21.96 l 4.186 -15.247 c 3.497 -16.18 -32.704 -2.439 -38.002 1.695 l 0.425 4.853 c 4.824 -3.395 23.091 -7.744 19.449 4.275 l -1.634 6.135 l 0 0 l -8.329 31.071 c -3.497 16.18 32.704 2.439 38.002 -1.695 l -0.425 -4.853 C 63.708 79.159 45.441 83.508 49.083 71.489 z"/>
            <circle cx="53.871" cy="11.201" r="11.201"/>
        </g>
    </svg>
</button>
