@php
    $theme = $mailTheme ?? null;
    $steps = \App\Support\TransactionalMailTheme::PROGRESS_STEPS;
    $last = count($steps);
@endphp
@if($theme && $theme->showProgress && $theme->currentStep > 0)
<table class="mail-progress" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:8px 0 16px 0;border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;">
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
    $leftColor = $leftOn ? '#3faeb2' : '#cbd5e1';
    $rightColor = $rightOn ? '#3faeb2' : '#cbd5e1';
    $labelFg = $current ? '#0b6266' : ($done ? '#1a585e' : '#64748b');
    $labelClass = $current ? 'mail-progress-label-current' : ($done ? 'mail-progress-label-done' : 'mail-progress-label-future');
    $dotClass = $current ? 'mail-progress-current' : ($done ? 'mail-progress-done' : 'mail-progress-future');
    $dotBg = $current ? '#ffffff' : ($done ? '#3faeb2' : '#94a3b8');
    $dotFg = $current ? '#0b6266' : '#ffffff';
    $mark = $done ? '✓' : (string) $n;
    $dotBorder = $current ? 'border:2px solid #0b6266;' : 'border:0;';
    $dotLine = $current ? '24px' : '28px';
@endphp
<td class="mail-progress-step" width="25%" align="center" valign="top" style="width:25%;padding:0;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-progress-line {{ $leftClass }}" valign="middle" height="32" style="height:32px;font-size:0;line-height:0;mso-line-height-rule:exactly;">
@if($n > 1)
<div style="height:2px;line-height:2px;font-size:1px;background-color:{{ $leftColor }};">&nbsp;</div>
@endif
</td>
<td class="mail-progress-dot-cell" width="28" height="32" align="center" valign="middle" style="width:28px;height:32px;min-width:28px;max-width:28px;padding:0;font-size:0;line-height:0;">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" style="width:28px;height:28px;" arcsize="50%" fillcolor="{{ $dotBg }}" strokecolor="{{ $current ? '#0b6266' : $dotBg }}" strokeweight="{{ $current ? '2px' : '0' }}">
<center style="color:{{ $dotFg }};font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:28px;">{{ $mark }}</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-->
<span class="{{ $dotClass }}" style="display:inline-block;width:28px;height:28px;min-width:28px;min-height:28px;max-width:28px;max-height:28px;box-sizing:border-box;background-color:{{ $dotBg }};color:{{ $dotFg }};border-radius:50%;{{ $dotBorder }}font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:{{ $dotLine }};mso-line-height-rule:exactly;text-align:center;vertical-align:middle;">{{ $mark }}</span>
<!--<![endif]-->
</td>
<td class="mail-progress-line {{ $rightClass }}" valign="middle" height="32" style="height:32px;font-size:0;line-height:0;mso-line-height-rule:exactly;">
@if($n < $last)
<div style="height:2px;line-height:2px;font-size:1px;background-color:{{ $rightColor }};">&nbsp;</div>
@endif
</td>
</tr>
</table>
<p class="{{ $labelClass }}" style="margin:6px 0 0 0;color:{{ $labelFg }};font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.3;font-weight:{{ $current ? '700' : '600' }};text-align:center;">{{ $label }}</p>
</td>
@endforeach
</tr>
</table>
@endif
