@component('mail::message')
Hello,

@include('emails.partials.mail-details-table', ['rows' => $detailRows ?? []])

@if(!empty($reason))
<table class="mail-protection" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;background-color:#fef3c7;border:1px solid #fde68a;border-radius:10px;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:12px 14px;color:#92400e;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;"><strong>Reason:</strong> {{ $reason }}</td>
</tr>
</table>
@endif

@component('mail::button', ['url' => $ctaUrl ?? rtrim(app_public_url(), '/').route('advertiser.catalog', [], false)])
Browse Publishers
@endcomponent
@endcomponent
