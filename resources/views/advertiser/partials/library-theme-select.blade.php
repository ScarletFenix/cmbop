@php
    $selectId = $selectId ?? '';
    $name = $name ?? $selectId;
    $label = $label ?? 'Choose';
    $options = $options ?? [];
    $current = (string) ($current ?? '');
    $currentLabel = $label;
    foreach ($options as $option) {
        if ((string) ($option['value'] ?? '') === $current) {
            $currentLabel = (string) ($option['label'] ?? $current);
            break;
        }
    }
@endphp
<div class="single-select-wrapper theme-select library-theme-select" data-theme-select="{{ $selectId }}">
    <select name="{{ $name }}" id="{{ $selectId }}" class="visually-hidden" tabindex="-1">
        @foreach($options as $option)
            <option value="{{ $option['value'] }}" @selected((string) $option['value'] === $current)>{{ $option['label'] }}</option>
        @endforeach
    </select>
    <div class="single-select-input" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="{{ $label }}">
        <span class="single-select-value">{{ $currentLabel }}</span>
        <i class="fa fa-chevron-down single-select-arrow" aria-hidden="true"></i>
    </div>
    <div class="single-select-dropdown">
        <div class="single-select-options" role="listbox" aria-label="{{ $label }}">
            @foreach($options as $option)
                <div class="single-select-option{{ (string) $option['value'] === $current ? ' selected' : '' }}"
                     role="option"
                     data-value="{{ $option['value'] }}"
                     data-label="{{ $option['label'] }}"
                     aria-selected="{{ (string) $option['value'] === $current ? 'true' : 'false' }}">
                    {{ $option['label'] }}
                </div>
            @endforeach
        </div>
    </div>
</div>
