@component('mail::message')
# {{ $viaPaypal ? 'Refund sent to PayPal' : 'Refund credited' }}

Hello,

Your link-removed dispute for order #{{ $dispute->order?->order_number ?? $dispute->order_id }} was upheld.

@if($viaPaypal)
{{ \App\Support\MailNotificationPresenter::euro((float) $credited) }} was refunded to your PayPal account.
@else
{{ \App\Support\MailNotificationPresenter::euro((float) $credited) }} has been credited back to your advertiser wallet.
@endif

@if($dispute->admin_notes)
Notes: {{ $dispute->admin_notes }}
@endif

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.balance', [], false)])
View balance
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
