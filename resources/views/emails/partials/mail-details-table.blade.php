@php
    $rows = \App\Support\MailNotificationPresenter::filledMailRows($rows ?? []);
@endphp
@if(!empty($rows))
<table class="mail-details" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;overflow:hidden;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
@foreach($rows as $index => $row)
@php
    $label = (string) ($row['label'] ?? '');
    $value = (string) ($row['value'] ?? '');
    $url = $row['url'] ?? null;
    $emphasis = ! empty($row['emphasis']);
    $last = $index === array_key_last($rows);
    $border = $last ? '' : 'border-bottom:1px solid #e2e8f0;';
    $valueColor = $emphasis ? '#b45309' : '#123f42';
    $valueClass = $emphasis ? 'mail-summary-value mail-deadline' : 'mail-summary-value';
@endphp
<tr>
<td class="mail-summary-label" valign="middle" style="width:38%;padding:11px 14px;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:400;{{ $border }}">{{ $label }}</td>
<td class="{{ $valueClass }}" valign="middle" align="right" style="padding:11px 14px;color:{{ $valueColor }};font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-align:right;{{ $border }}word-break:normal;overflow-wrap:anywhere;">
@if(filled($url))
<a class="mail-url" href="{{ $url }}" style="color:#1a585e;word-break:normal;overflow-wrap:anywhere;font-family:Arial,Helvetica,sans-serif;font-weight:700;">{{ $value !== '' ? $value : $url }}</a>
@else
{{ $value }}
@endif
</td>
</tr>
@endforeach
</table>
@endif
