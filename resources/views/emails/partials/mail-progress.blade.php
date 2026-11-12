@php
    $theme = $mailTheme ?? null;
    $steps = \App\Support\TransactionalMailTheme::PROGRESS_STEPS;
    $last = count($steps);
    $bodyBg = '#f7fafb';
@endphp
@if($theme && $theme->showProgress && $theme->currentStep > 0)
<table class="mail-progress" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:8px 0 22px 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
@foreach($steps as $i => $label)
@php
    $n = $i + 1;
    $done = $n < $theme->currentStep;
    $current = $n === $theme->currentStep;
    $leftOn = $n > 1 && $n <= $theme->currentStep;
    $rightOn = $n < $theme->currentStep;
    $leftClass = $n === 1 ? 'mail-progress-line-edge' : ($leftOn ? 'mail-progress-line-on' : 'mail-progress-line-off');
    $rightClass = $n === $last ? 'mail-progress-line-edge' : ($rightOn ? 'mail-progress-line-on' : 'mail-progress-line-off');
    $leftColor = $n === 1 ? $bodyBg : ($leftOn ? '#3faeb2' : '#cbd5e1');
    $rightColor = $n === $last ? $bodyBg : ($rightOn ? '#3faeb2' : '#cbd5e1');
    $labelFg = $current ? '#0b6266' : ($done ? '#1a585e' : '#64748b');
    $labelClass = $current ? 'mail-progress-label-current' : ($done ? 'mail-progress-label-done' : 'mail-progress-label-future');
@endphp
<td class="mail-progress-step" align="center" valign="top" style="width:25%;padding:0;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-progress-line {{ $leftClass }}" valign="middle" style="width:50%;height:14px;border-top:2px solid {{ $leftColor }};font-size:0;line-height:0;">&nbsp;</td>
<td width="24" align="center" valign="middle" style="width:24px;padding:0;">
@if($done)
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td class="mail-progress-done" width="22" height="22" align="center" valign="middle" style="width:22px;height:22px;border-radius:11px;background-color:#3faeb2;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;line-height:22px;">✓</td>
</tr></table>
@elseif($current)
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td class="mail-progress-current" width="22" height="22" align="center" valign="middle" style="width:22px;height:22px;border-radius:11px;background-color:#ffffff;border:2px solid #0b6266;color:#0b6266;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;line-height:18px;">{{ $n }}</td>
</tr></table>
@else
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td class="mail-progress-future" width="22" height="22" align="center" valign="middle" style="width:22px;height:22px;border-radius:11px;background-color:#94a3b8;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;line-height:22px;">{{ $n }}</td>
</tr></table>
@endif
</td>
<td class="mail-progress-line {{ $rightClass }}" valign="middle" style="width:50%;height:14px;border-top:2px solid {{ $rightColor }};font-size:0;line-height:0;">&nbsp;</td>
</tr>
</table>
<p class="{{ $labelClass }}" style="margin:8px 0 0 0;color:{{ $labelFg }};font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.3;font-weight:{{ $current ? '700' : '600' }};text-align:center;">{{ $label }}</p>
</td>
@endforeach
</tr>
</table>
@endif
