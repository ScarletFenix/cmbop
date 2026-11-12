@php
    $rows = \App\Support\MailNotificationPresenter::filledMailRows($rows ?? []);
@endphp
@if(!empty($rows))
<table class="mail-details" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;font-family:Arial,Helvetica,sans-serif;">
@foreach($rows as $index => $row)
@php
    $label = (string) ($row['label'] ?? '');
    $value = (string) ($row['value'] ?? '');
    $url = $row['url'] ?? null;
    $last = $index === array_key_last($rows);
    $border = $last ? '' : 'border-bottom:1px solid #e2e8f0;';
@endphp
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;{{ $border }}">{{ $label }}</td>
<td class="mail-summary-value" valign="top" style="padding:10px 14px;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:14px;{{ $border }}word-break:break-word;">
@if(filled($url))
<a class="mail-url" href="{{ $url }}" style="color:#1a585e;word-break:break-all;font-family:Arial,Helvetica,sans-serif;">{{ $value !== '' ? $value : $url }}</a>
@else
{{ $value }}
@endif
</td>
</tr>
@endforeach
</table>
@endif
