@php
    $card = $card ?? [];
    $domain = (string) ($card['domain'] ?? 'Website');
    $price = $card['price'] ?? null;
    $priceLabel = $price !== null ? 'from €'.number_format((float) $price, 2) : null;
    $hasShot = ! empty($card['has_screenshot']) && ! empty($card['screenshot_url']);
@endphp
<table class="mail-card mail-item-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;border-radius:12px;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;">
@if($hasShot)
<tr>
<td style="padding:0;background-color:#e6f5f5;text-align:center;">
<img src="{{ $card['screenshot_url'] }}" alt="Homepage preview of {{ $domain }}" width="552" style="display:block;width:100%;max-height:160px;border:0;background:transparent;">
</td>
</tr>
@endif
<tr>
<td style="padding:16px;font-family:Arial,Helvetica,sans-serif;">
<p style="margin:0 0 6px 0;font-size:16px;font-weight:700;color:#123f42;font-family:Arial,Helvetica,sans-serif;">{{ $domain }}</p>
@if(!empty($card['niche']) || !empty($card['country_label']))
<p style="margin:0 0 10px 0;font-size:13px;color:#334155;font-family:Arial,Helvetica,sans-serif;">
{{ $card['niche'] ?? '' }}
@if(!empty($card['country_label']) && !empty($card['niche']))
 ·
@endif
{{ $card['country_label'] ?? '' }}
</p>
@endif
<p style="margin:0 0 12px 0;font-size:13px;line-height:1.45;color:#334155;font-family:Arial,Helvetica,sans-serif;">
@if(($card['da'] ?? null) !== null) DA {{ $card['da'] }} @endif
@if(($card['dr'] ?? null) !== null) · DR {{ $card['dr'] }} @endif
</p>
@if($priceLabel)
<p style="margin:0 0 14px 0;font-size:15px;font-weight:700;color:#1a585e;font-family:Arial,Helvetica,sans-serif;">{{ $priceLabel }}</p>
@endif
@if(!empty($card['details_url']))
<a class="mail-btn" href="{{ $card['details_url'] }}" style="display:inline-block;background-color:#1a585e;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;padding:10px 16px;border-radius:8px;font-family:Arial,Helvetica,sans-serif;">View site</a>
@endif
</td>
</tr>
</table>
