{{--
    The mark of the tool a metric comes from.

    These used to be 16px raster crops repeated beside every number, which made a
    long results table noisy without telling anyone anything new on row 40. A
    metric's source is a property of the column, not of the listing, so the mark
    belongs wherever the column is named once: the table head, and the metric
    labels on the card layout, which has no table head to carry it.

    @param string $type dr | da | traffic
    @param string $size sm (card labels) | md (table head)
--}}
@php
    $sourceType = $type ?? 'dr';
    $sourceSize = ($size ?? 'md') === 'sm' ? 'sm' : 'md';

    $sources = [
        'dr' => ['file' => 'assets/img/ahref.jpeg', 'name' => 'Ahrefs', 'fit' => 'cover', 'blend' => null],
        'da' => ['file' => 'assets/img/moz_da.png', 'name' => 'Moz', 'fit' => 'contain', 'blend' => 'multiply'],
        'traffic' => ['file' => 'assets/img/traffic.svg', 'name' => 'Analytics', 'fit' => 'contain', 'blend' => null],
    ];

    $source = $sources[$sourceType] ?? null;
@endphp

@if($source)
    {{-- Decorative: the column heading and its tip already say which tool the
         number comes from, so announcing the logo as well only repeats it. --}}
    <span class="metric-source metric-source--{{ $sourceSize }} metric-source--fit-{{ $source['fit'] }}{{ ! empty($source['blend']) ? ' metric-source--blend-'.$source['blend'] : '' }}"
          title="Source: {{ $source['name'] }}"
          aria-hidden="true">
        <img src="{{ asset($source['file']) }}"
             alt=""
             loading="lazy"
             decoding="async"
             onerror="this.closest('.metric-source').style.display='none'">
    </span>
@endif
