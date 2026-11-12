@php
    $live = $articleLive ?? null;
@endphp
@if(is_array($live))
@php
    $hasShot = ! empty($live['preview_url']);
    $hasAnchor = ! empty($live['anchor_text']) || ! empty($live['target_url']) || ! empty($live['show_dofollow_pill']);
    $anchor = (string) ($live['anchor_text'] ?? '');
    $targetHref = $live['target_url'] ?? null;
    $targetText = (string) ($live['target_url_display'] ?? '');
    if ($targetText === '' && is_string($targetHref)) {
        $targetText = \App\Support\MailNotificationPresenter::displayUrl($targetHref);
    }
@endphp
@if($hasShot || $hasAnchor)
<table class="mail-card mail-anchor" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background-color:#f7fafb;font-family:Arial,Helvetica,sans-serif;">
@if($hasShot)
<tr>
<td style="padding:0;background-color:#e6f5f5;text-align:center;">
<img src="{{ $live['preview_url'] }}" alt="Article preview" width="552" style="display:block;width:100%;max-height:180px;border:0;background:transparent;">
</td>
</tr>
@endif
@if($hasAnchor)
<tr>
<td style="padding:12px 14px;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:Arial,Helvetica,sans-serif;">
<tr>
<td valign="middle" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#334155;word-break:break-word;">
<span style="color:#64748b;">Anchor text:</span>
@if($anchor !== '')
<strong style="color:#123f42;font-weight:700;"> {{ $anchor }}</strong>
@endif
@if($targetHref)
 → <a class="mail-url" href="{{ $targetHref }}" style="color:#1a585e;word-break:break-all;">{{ $targetText !== '' ? $targetText : $targetHref }}</a>
@endif
</td>
@if(!empty($live['show_dofollow_pill']))
<td valign="middle" align="right" width="130" style="padding-left:10px;white-space:nowrap;">
<span style="display:inline-block;background-color:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:4px 8px;border-radius:999px;font-family:Arial,Helvetica,sans-serif;">Dofollow verified</span>
</td>
@endif
</tr>
</table>
</td>
</tr>
@endif
</table>
@endif

@php
    $liveDisplay = (string) ($live['live_url_display'] ?? '');
    if ($liveDisplay === '' && ! empty($live['live_url'])) {
        $liveDisplay = \App\Support\MailNotificationPresenter::displayUrl($live['live_url']);
    }
    $detailRows = \App\Support\MailNotificationPresenter::filledMailRows([
        ['label' => 'Website', 'value' => $live['site_domain'] ?? $live['site_name'] ?? ''],
        ['label' => 'Live URL', 'value' => $liveDisplay, 'url' => $live['live_url'] ?? null],
        ['label' => 'Order', 'value' => !empty($live['order_number']) ? '#'.$live['order_number'] : ''],
        ['label' => 'Review by', 'value' => $live['review_by'] ?? '', 'emphasis' => !empty($live['review_by'])],
        ['label' => 'Homepage placement', 'value' => $live['homepage_placement'] ?? ''],
        ['label' => 'Social promotion', 'value' => $live['social_promotion'] ?? ''],
    ]);
    foreach (($live['social_urls'] ?? []) as $social) {
        if (! empty($social['url'])) {
            $detailRows[] = [
                'label' => $social['label'] ?? 'Social post',
                'value' => \App\Support\MailNotificationPresenter::displayUrl($social['url']) ?: $social['url'],
                'url' => $social['url'],
            ];
        }
    }
    $detailRows = \App\Support\MailNotificationPresenter::filledMailRows($detailRows);
@endphp
@include('emails.partials.mail-details-table', ['rows' => $detailRows])

<table class="mail-btn-row" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:4px 0 12px 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td align="center" style="padding:0;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center" style="margin:0 auto;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td class="mail-btn-cell" align="center" valign="middle" style="padding:0 6px 0 0;">
<a class="mail-btn" href="{{ $live['approve_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#1a585e;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:12px 20px;border-radius:8px;border:2px solid #1a585e;">Approve article</a>
</td>
<td class="mail-btn-cell" align="center" valign="middle" style="padding:0 0 0 6px;">
<a class="mail-btn-outline" href="{{ $live['corrections_url'] }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#ffffff;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;text-decoration:none;padding:10px 18px;border-radius:8px;border:2px solid #1a585e;">Request corrections</a>
</td>
</tr>
</table>
</td>
</tr>
</table>

<p class="mail-subline" style="margin:0 0 16px 0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;text-align:center;">No action by the deadline? The order is approved automatically.</p>

@if(!empty($live['show_protection']))
<table class="mail-protection" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 8px 0;background-color:#e6f5f5;border:1px solid #d4f1f0;border-radius:10px;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:12px 14px;color:#123f42;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;">🔒 <strong>Your payment stays protected</strong> until you approve the live article. Only then is it released to the publisher.</td>
</tr>
</table>
@endif
@endif
