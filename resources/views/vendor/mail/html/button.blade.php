@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
@php
    $isOutline = in_array($color, ['outline', 'secondary'], true);
    $btnClass = $isOutline ? 'mail-btn-outline button button-outline' : 'mail-btn button button-'.$color;
    $btnStyle = $isOutline
        ? 'display:inline-block;background-color:transparent;color:#1a585e;font-size:14px;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:8px;border:2px solid #1a585e;'
        : 'display:inline-block;background-color:#1a585e;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:12px 20px;border-radius:8px;';
@endphp
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="{{ $btnClass }}" target="_blank" rel="noopener" style="{{ $btnStyle }}">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
