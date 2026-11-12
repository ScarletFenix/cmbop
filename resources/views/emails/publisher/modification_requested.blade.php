@component('mail::message')
Hello,

@include('emails.partials.mail-details-table', ['rows' => $detailRows ?? []])

@if(!empty($reason))
<table class="mail-protection" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 16px 0;background-color:#fef3c7;border:1px solid #fde68a;border-radius:10px;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td style="padding:12px 14px;color:#92400e;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;"><strong>Correction requested:</strong> {{ $reason }}</td>
</tr>
</table>
@endif

The {{ $autoApproveHours ?? \App\Models\OrderItem::autoApproveHours() }}-hour auto-approve timer has been stopped. The countdown will restart once you resubmit the updated live URL.

@component('mail::button', ['url' => $ctaUrl ?? rtrim(app_public_url(), '/').route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false)])
View Order
@endcomponent
@endcomponent
