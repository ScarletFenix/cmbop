@php
    $theme = $mailTheme ?? null;
    $steps = \App\Support\TransactionalMailTheme::PROGRESS_STEPS;
@endphp
@if($theme && $theme->showProgress && $theme->currentStep > 0)
<table class="mail-progress" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:8px 0 20px 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
@foreach($steps as $i => $label)
@php
    $n = $i + 1;
    $done = $n < $theme->currentStep;
    $current = $n === $theme->currentStep;
    $dotClass = $current ? 'mail-progress-current' : ($done ? 'mail-progress-done' : 'mail-progress-future');
    $labelClass = $current ? 'mail-progress-label-current' : ($done ? 'mail-progress-label-done' : 'mail-progress-label-future');
    $dotBg = $current ? '#0b6266' : ($done ? '#3faeb2' : '#94a3b8');
    $dotFg = '#ffffff';
    $labelFg = $current ? '#0b6266' : ($done ? '#1a585e' : '#64748b');
    $mark = $done ? '✓' : (string) $n;
@endphp
<td class="mail-progress-step" align="center" valign="top" style="width:25%;padding:0 2px;font-family:Arial,Helvetica,sans-serif;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center" style="font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="{{ $dotClass }}" align="center" valign="middle" style="width:22px;height:22px;border-radius:11px;background-color:{{ $dotBg }};color:{{ $dotFg }};font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;line-height:22px;">{{ $mark }}</td>
</tr>
</table>
<p class="{{ $labelClass }}" style="margin:6px 0 0 0;color:{{ $labelFg }};font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.3;font-weight:{{ $current ? '700' : '600' }};">{{ $label }}</p>
</td>
@if(!$loop->last)
<td class="mail-progress-join{{ ($done || $current) ? '' : ' mail-progress-join-muted' }}" valign="middle" style="width:8px;padding:0 0 18px 0;">
<div style="height:2px;background-color:{{ $done || $current ? '#3faeb2' : '#94a3b8' }};font-size:1px;line-height:2px;">&nbsp;</div>
</td>
@endif
@endforeach
</tr>
</table>
@endif
