@php
    $theme = $mailTheme ?? null;
@endphp
@if($theme)
<table class="mail-hero" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;">
<tr>
<td align="left" style="padding:0 0 12px 0;">
@if($theme->showSuccessIcon && $theme->successIconUrl)
<img src="{{ $theme->successIconUrl }}" width="36" height="36" alt="" style="display:inline-block;border:0;outline:none;width:36px;height:36px;background:transparent;vertical-align:middle;margin:0 10px 0 0;">
@endif
@if($theme->showPill && $theme->pillLabel !== '')
<span class="mail-pill" style="display:inline-block;background-color:{{ $theme->pillBg }};color:{{ $theme->pillFg }};font-size:12px;font-weight:700;letter-spacing:0.02em;line-height:1;padding:6px 10px;border-radius:999px;vertical-align:middle;">{{ $theme->pillLabel }}</span>
@endif
</td>
</tr>
@if($theme->heading !== '')
<tr>
<td class="mail-heading" style="padding:0 0 6px 0;color:#1a585e;font-size:24px;line-height:1.25;font-weight:700;">{{ $theme->heading }}</td>
</tr>
@endif
@if($theme->subline !== '')
<tr>
<td class="mail-subline" style="padding:0 0 8px 0;color:#334155;font-size:15px;line-height:1.5;">{{ $theme->subline }}</td>
</tr>
@endif
</table>
@endif
