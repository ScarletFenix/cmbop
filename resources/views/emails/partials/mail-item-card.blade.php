@php
    $card = $card ?? [];
    $name = (string) ($card['domain'] ?? $card['site_name'] ?? 'Website');
    $url = $card['site_url'] ?? null;
    $price = $card['price'] ?? null;
    $showPrice = array_key_exists('show_price', $card)
        ? ! empty($card['show_price'])
        : \App\Support\MailNotificationPresenter::hasMailValue($price);
    $content = $card['content_link'] ?? null;
@endphp
<table class="mail-item-card mail-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 12px 0;border:1px solid #e2e8f0;border-radius:12px;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;">
<p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:1.3;">
@if(!empty($url))
<a class="mail-item-domain" href="{{ $url }}" style="color:#123f42;font-weight:700;text-decoration:none;">{{ $name }}</a>
@else
<span class="mail-item-domain" style="color:#123f42;">{{ $name }}</span>
@endif
</p>
@if(!empty($card['product_type_label']) && $card['product_type_label'] === 'Link insertion')
<p style="margin:0 0 8px 0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:13px;">Link insertion · Permanent</p>
@endif
@if($showPrice && \App\Support\MailNotificationPresenter::hasMailValue($price))
<p style="margin:0 0 8px 0;color:#123f42;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;">{{ $price }}</p>
@endif
@if(!empty($card['link_guarantee']))
<p style="margin:0 0 8px 0;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:13px;">
Link guarantee
@if(!empty($card['link_guarantee_fee_label']))
 — {{ $card['link_guarantee_fee_label'] }}
@endif
</p>
@endif
@if(!empty($content))
<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.4;">
<a class="mail-url" href="{{ $content }}" style="color:#1a585e;word-break:normal;overflow-wrap:anywhere;">View your content</a>
</p>
@endif
</td>
</tr>
</table>
