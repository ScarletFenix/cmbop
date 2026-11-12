@php
    $theme = $mailTheme ?? null;
@endphp
@if($theme)
<table class="mail-hero" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td align="left" style="padding:0 0 14px 0;">
<table cellpadding="0" cellspacing="0" role="presentation" style="font-family:Arial,Helvetica,sans-serif;">
<tr>
@if($theme->showSuccessIcon)
<td valign="middle" style="padding:0 12px 0 0;">
<table class="mail-tick-wrap" role="presentation" cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-tick" width="36" height="36" align="center" valign="middle" style="width:36px;height:36px;background-color:#0b6266;border-radius:18px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:700;line-height:36px;">✓</td>
</tr>
</table>
</td>
@endif
@if($theme->showPill && $theme->pillLabel !== '')
<td valign="middle" style="padding:0;">
<span class="mail-pill" style="display:inline-block;background-color:{{ $theme->pillBg }};color:{{ $theme->pillFg }};font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:0.02em;line-height:1;padding:7px 12px;border-radius:999px;vertical-align:middle;">{{ $theme->pillLabel }}</span>
</td>
@endif
</tr>
</table>
</td>
</tr>
@if($theme->heading !== '')
<tr>
<td class="mail-heading" style="padding:4px 0 8px 0;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:1.25;font-weight:700;">{{ $theme->heading }}</td>
</tr>
@endif
@if($theme->subline !== '')
<tr>
<td class="mail-subline" style="padding:0 0 8px 0;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;">{{ $theme->subline }}</td>
</tr>
@endif
</table>
@endif
