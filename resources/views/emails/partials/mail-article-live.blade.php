@php
    $live = $articleLive ?? null;
@endphp
@if(is_array($live))
@if(!empty($live['preview_url']))
<table class="mail-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:0;background-color:#e6f5f5;text-align:center;">
<img src="{{ $live['preview_url'] }}" alt="Article preview" width="552" style="display:block;width:100%;max-height:180px;border:0;background:transparent;">
</td>
</tr>
</table>
@endif

@if(!empty($live['anchor_text']) || !empty($live['target_url']))
<table class="mail-anchor mail-card" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;background-color:#f7fafb;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:12px 14px;font-family:Arial,Helvetica,sans-serif;">
@if(!empty($live['anchor_text']))
<p style="margin:0 0 4px 0;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;">Anchor text</p>
<p style="margin:0 0 10px 0;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.4;">{{ $live['anchor_text'] }}</p>
@endif
@if(!empty($live['target_url']))
<p style="margin:0 0 4px 0;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;">Target URL</p>
<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.4;word-break:break-all;">
<a class="mail-url" href="{{ $live['target_url'] }}" style="color:#1a585e;word-break:break-all;">{{ $live['target_url'] }}</a>
</p>
@endif
@if(!empty($live['dofollow_verified']))
<p style="margin:10px 0 0 0;">
<span style="display:inline-block;background-color:#d4f1f0;color:#0b6266;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;font-family:Arial,Helvetica,sans-serif;">Dofollow verified</span>
</p>
@endif
</td>
</tr>
</table>
@elseif(!empty($live['dofollow_verified']))
<p style="margin:0 0 16px 0;">
<span style="display:inline-block;background-color:#d4f1f0;color:#0b6266;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;font-family:Arial,Helvetica,sans-serif;">Dofollow verified</span>
</p>
@endif

@php
    $detailRows = \App\Support\MailNotificationPresenter::filledMailRows([
        ['label' => 'Website name', 'value' => $live['site_name'] ?? ''],
        ['label' => 'Live URL', 'value' => $live['live_url'] ?? '', 'url' => $live['live_url'] ?? null],
        ['label' => 'Order number', 'value' => !empty($live['order_number']) ? '#'.$live['order_number'] : ''],
        ['label' => 'Review by', 'value' => $live['review_by'] ?? ''],
        ['label' => 'Homepage placement', 'value' => $live['homepage_placement'] ?? ''],
        ['label' => 'Social promotion', 'value' => $live['social_promotion'] ?? ''],
    ]);
    foreach (($live['social_urls'] ?? []) as $social) {
        if (! empty($social['url'])) {
            $detailRows[] = [
                'label' => $social['label'] ?? 'Social post',
                'value' => $social['url'],
                'url' => $social['url'],
            ];
        }
    }
    $detailRows = \App\Support\MailNotificationPresenter::filledMailRows($detailRows);
@endphp
@include('emails.partials.mail-details-table', ['rows' => $detailRows])

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-stack" align="center" style="padding:0 0 8px 0;">
<a class="mail-btn" href="{{ $live['approve_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#1a585e;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:12px 20px;border-radius:8px;">Approve article</a>
</td>
</tr>
<tr>
<td class="mail-stack" align="center" style="padding:0 0 8px 0;">
<a class="mail-btn-outline" href="{{ $live['corrections_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:transparent;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:8px;border:2px solid #1a585e;">Request corrections</a>
</td>
</tr>
</table>

<p style="margin:0 0 16px 0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;">No action by the deadline? The order is approved automatically.</p>

@if(!empty($live['show_protection']))
<table class="mail-protection" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 8px 0;background-color:#e6f5f5;border:1px solid #d4f1f0;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:12px 14px;color:#123f42;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;">Your payment stays protected until you approve the live article. Only then is it released to the publisher.</td>
</tr>
</table>
@endif
@endif
