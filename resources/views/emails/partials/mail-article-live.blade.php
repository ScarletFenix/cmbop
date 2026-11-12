@php
    $live = $articleLive ?? null;
@endphp
@if(is_array($live))
@if(!empty($live['preview_url']))
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;">
<tr>
<td style="padding:0;background-color:#e6f5f5;text-align:center;">
<img src="{{ $live['preview_url'] }}" alt="Article preview" width="552" style="display:block;width:100%;max-height:180px;border:0;background:transparent;">
</td>
</tr>
</table>
@else
<table class="mail-preview-placeholder" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#e6f5f5;">
<tr>
<td style="padding:28px 16px;text-align:center;color:#1a585e;font-size:14px;line-height:1.4;">
Live article on {{ $live['site_name'] }}
</td>
</tr>
</table>
@endif

@if(!empty($live['anchor_text']) || !empty($live['target_url']))
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;">
<tr>
<td style="padding:12px 14px;">
<p style="margin:0 0 4px 0;color:#1a585e;font-size:12px;font-weight:700;">Anchor text</p>
<p style="margin:0 0 10px 0;color:#334155;font-size:14px;line-height:1.4;">{{ $live['anchor_text'] ?: '—' }}</p>
<p style="margin:0 0 4px 0;color:#1a585e;font-size:12px;font-weight:700;">Target URL</p>
@if(!empty($live['target_url']))
<p style="margin:0;font-size:14px;line-height:1.4;word-break:break-all;">
<a class="mail-url" href="{{ $live['target_url'] }}" style="color:#1a585e;word-break:break-all;">{{ $live['target_url'] }}</a>
</p>
@else
<p style="margin:0;color:#64748b;font-size:14px;">—</p>
@endif
@if(!empty($live['dofollow_verified']))
<p style="margin:10px 0 0 0;">
<span style="display:inline-block;background-color:#d4f1f0;color:#0b6266;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;">Dofollow verified</span>
</p>
@endif
</td>
</tr>
</table>
@elseif(!empty($live['dofollow_verified']))
<p style="margin:0 0 16px 0;">
<span style="display:inline-block;background-color:#d4f1f0;color:#0b6266;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;">Dofollow verified</span>
</p>
@endif

<table class="mail-details" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;">
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px;color:#1a585e;font-size:13px;font-weight:700;border-bottom:1px solid #e2e8f0;">Website name</td>
<td valign="top" style="padding:10px 14px;color:#334155;font-size:14px;border-bottom:1px solid #e2e8f0;">{{ $live['site_name'] }}</td>
</tr>
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px;color:#1a585e;font-size:13px;font-weight:700;border-bottom:1px solid #e2e8f0;">Live URL</td>
<td valign="top" style="padding:10px 14px;font-size:14px;border-bottom:1px solid #e2e8f0;word-break:break-all;">
@if(!empty($live['live_url']))
<a class="mail-url" href="{{ $live['live_url'] }}" style="color:#1a585e;word-break:break-all;">{{ $live['live_url'] }}</a>
@else
<span style="color:#64748b;">Not submitted yet</span>
@endif
</td>
</tr>
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px;color:#1a585e;font-size:13px;font-weight:700;border-bottom:1px solid #e2e8f0;">Order number</td>
<td valign="top" style="padding:10px 14px;color:#334155;font-size:14px;border-bottom:1px solid #e2e8f0;">#{{ $live['order_number'] }}</td>
</tr>
<tr>
<td class="mail-summary-label" valign="top" style="width:38%;padding:10px 14px;color:#1a585e;font-size:13px;font-weight:700;">Review by</td>
<td valign="top" style="padding:10px 14px;color:#334155;font-size:14px;">{{ $live['review_by'] ?: '—' }}</td>
</tr>
</table>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 12px 0;">
<tr>
<td class="mail-stack" align="center" style="padding:0 0 8px 0;">
<a class="mail-btn" href="{{ $live['approve_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#1a585e;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:12px 20px;border-radius:8px;">Approve article</a>
</td>
</tr>
<tr>
<td class="mail-stack" align="center" style="padding:0 0 8px 0;">
<a class="mail-btn-outline" href="{{ $live['corrections_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:transparent;color:#1a585e;font-size:14px;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:8px;border:2px solid #1a585e;">Request corrections</a>
</td>
</tr>
</table>

<p style="margin:0 0 16px 0;color:#64748b;font-size:13px;line-height:1.5;">No action by the deadline? The order is approved automatically.</p>

@if(!empty($live['show_protection']))
<table class="mail-protection" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 8px 0;background-color:#e6f5f5;border:1px solid #d4f1f0;">
<tr>
<td style="padding:12px 14px;color:#123f42;font-size:13px;line-height:1.5;">Your payment stays protected until you approve the live article. Only then is it released to the publisher.</td>
</tr>
</table>
@endif
@endif
