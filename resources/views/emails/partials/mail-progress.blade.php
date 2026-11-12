@php
    $theme = $mailTheme ?? null;
    $steps = \App\Support\TransactionalMailTheme::PROGRESS_STEPS;
@endphp
@if($theme && $theme->showProgress && $theme->currentStep > 0)
<table class="mail-progress" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:8px 0 20px 0;">
<tr>
@foreach($steps as $i => $label)
@php
    $n = $i + 1;
    $done = $n < $theme->currentStep;
    $current = $n === $theme->currentStep;
    $dotBg = $current ? '#0b6266' : ($done ? '#3faeb2' : '#e2e8f0');
    $dotFg = ($current || $done) ? '#ffffff' : '#64748b';
    $labelFg = $current ? '#0b6266' : ($done ? '#1a585e' : '#94a3b8');
@endphp
<td class="mail-progress-step" align="center" valign="top" style="width:25%;padding:0 2px;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td align="center" style="width:22px;height:22px;border-radius:11px;background-color:{{ $dotBg }};color:{{ $dotFg }};font-size:11px;font-weight:700;line-height:22px;">{{ $n }}</td>
</tr>
</table>
<p style="margin:6px 0 0 0;color:{{ $labelFg }};font-size:11px;line-height:1.3;font-weight:{{ $current ? '700' : '600' }};">{{ $label }}</p>
</td>
@if(!$loop->last)
<td class="mail-progress-join" valign="middle" style="width:8px;padding:0 0 18px 0;">
<div style="height:2px;background-color:{{ $done || $current ? '#3faeb2' : '#e2e8f0' }};font-size:1px;line-height:2px;">&nbsp;</div>
</td>
@endif
@endforeach
</tr>
</table>
@endif
