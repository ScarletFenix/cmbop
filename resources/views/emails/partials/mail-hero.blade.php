@php
    $theme = $mailTheme ?? null;
@endphp
@if($theme)
<table class="mail-hero" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;font-family:Arial,Helvetica,sans-serif;">
@if($theme->showHeroIcon)
<tr>
<td align="center" style="padding:4px 0 14px 0;">
<table class="mail-tick-wrap" role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-tick" width="56" height="56" align="center" valign="middle" style="width:56px;height:56px;background-color:{{ $theme->heroIconBg }};border-radius:28px;color:{{ $theme->heroIconFg }};font-family:Arial,Helvetica,sans-serif;font-size:28px;font-weight:700;line-height:56px;">{{ $theme->heroMark }}</td>
</tr>
</table>
</td>
</tr>
@endif
@if($theme->showPill && $theme->pillLabel !== '')
<tr>
<td align="center" style="padding:0 0 14px 0;">
<span class="mail-pill" style="display:inline-block;background-color:{{ $theme->pillBg }};color:{{ $theme->pillFg }};font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;line-height:1;padding:7px 14px;border-radius:999px;vertical-align:middle;">{{ $theme->pillLabel }}</span>
</td>
</tr>
@endif
@if($theme->heading !== '')
<tr>
<td class="mail-heading" align="center" style="padding:0 8px 10px 8px;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:1.3;font-weight:700;text-align:center;">{{ $theme->heading }}</td>
</tr>
@endif
@if($theme->subline !== '')
<tr>
<td class="mail-subline" align="center" style="padding:0 12px 8px 12px;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;text-align:center;">{{ $theme->subline }}</td>
</tr>
@endif
</table>
@endif
