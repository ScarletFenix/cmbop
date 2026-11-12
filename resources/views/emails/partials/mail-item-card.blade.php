@php
    $card = $card ?? [];
    $name = (string) ($card['domain'] ?? $card['site_name'] ?? 'Website');
    $url = $card['site_url'] ?? null;
    $urlDisplay = (string) ($card['site_url_display'] ?? '');
    if ($urlDisplay === '' && is_string($url)) {
        $urlDisplay = \App\Support\MailNotificationPresenter::displayUrl($url);
    }
    $price = $card['price'] ?? null;
    $content = $card['content_link'] ?? null;
@endphp
<table class="mail-item-card mail-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;border-radius:12px;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;">
<p style="margin:0 0 6px 0;color:#123f42;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:1.3;">{{ $name }}</p>
@if(!empty($url))
<p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.4;word-break:break-all;">
<a class="mail-url" href="{{ $url }}" style="color:#1a585e;word-break:break-all;">{{ $urlDisplay !== '' ? $urlDisplay : $url }}</a>
</p>
@endif
@if(\App\Support\MailNotificationPresenter::hasMailValue($price))
<p style="margin:0 0 8px 0;color:#123f42;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;">{{ $price }}</p>
@endif
@if(!empty($content))
<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.4;word-break:break-all;">
<a class="mail-url" href="{{ $content }}" style="color:#1a585e;word-break:break-all;">Content</a>
</p>
@endif
</td>
</tr>
</table>
