@php
    $liPrice = \App\Support\LinkInsertionProduct::staffListPrice($site ?? null);
@endphp
@if($liPrice !== null)
    <div class="small">
        <span class="badge text-bg-success">LI</span>
        €{{ number_format($liPrice, 2) }}
    </div>
@else
    <span class="text-muted">—</span>
@endif
