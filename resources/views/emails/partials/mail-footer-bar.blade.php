@php
    $theme = $mailTheme ?? null;
    $support = $theme->supportEmail ?? 'support@seolinkbuildings.com';
    $helpUrl = $theme->helpUrl ?? 'https://seolinkbuildings.com/faq';
    $prefsUrl = $theme->notificationSettingsUrl ?? 'https://seolinkbuildings.com/profile/notifications';
    $unsub = $theme->unsubscribeUrl ?? ($unsubscribeUrl ?? null);
    $showUnsub = ($theme->isMarketing ?? false) && filled($unsub);
@endphp
<table class="mail-footer-bar footer" width="600" cellpadding="0" cellspacing="0" role="presentation" style="width:600px;max-width:600px;margin:0 auto;background-color:#f4fbfb;">
<tr>
<td class="mail-pad" style="padding:20px 24px 28px 24px;text-align:center;">
<p class="mail-footer-copy" style="margin:0 0 8px 0;color:#334155;font-size:13px;line-height:1.5;">Questions? Just reply to this email or write to <a href="mailto:{{ $support }}" style="color:#1a585e;text-decoration:underline;">{{ $support }}</a></p>
<p class="mail-footer-copy" style="margin:0 0 12px 0;color:#123f42;font-size:12px;line-height:1.5;">SEOLinkBuildings · European guest post marketplace · 1000+ publishers</p>
<p class="mail-footer-copy" style="margin:0;color:#64748b;font-size:12px;line-height:1.5;">
<a href="{{ $helpUrl }}" style="color:#1a585e;text-decoration:underline;">Help center</a>
&nbsp;·&nbsp;
<a href="{{ $prefsUrl }}" style="color:#1a585e;text-decoration:underline;">Notification settings</a>
@if($showUnsub)
&nbsp;·&nbsp;
<a href="{{ $unsub }}" style="color:#1a585e;text-decoration:underline;">Unsubscribe</a>
@endif
</p>
</td>
</tr>
</table>
