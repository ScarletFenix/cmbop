@php
    $preheader = trim((string) ($mailTheme->preheader ?? $preheader ?? ''));
@endphp
@if($preheader !== '')
<div class="mail-preheader" style="display:none;font-size:1px;color:#f4fbfb;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
{{ $preheader }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
@endif
