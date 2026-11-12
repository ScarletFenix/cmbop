@component('mail::message')
# PayPal deposit refunded

Hello,

{{ \App\Support\MailNotificationPresenter::euro((float) $deposit->amount) }} from your PayPal Add Funds deposit was refunded and removed from your wallet.

## Deposit details

- Amount: {{ \App\Support\MailNotificationPresenter::euro((float) $deposit->amount) }}
- Reference code: {{ $deposit->reference_code }}
- Payment method: {{ $methodLabel }}
@if(($debt ?? 0) > 0.009)
- Outstanding wallet debt: {{ \App\Support\MailNotificationPresenter::euro((float) $debt) }}
@endif

@if(($debt ?? 0) > 0.009)
Part of this deposit had already been spent, so {{ \App\Support\MailNotificationPresenter::euro((float) $debt) }} remains as outstanding wallet debt.
@endif

@component('mail::button', ['url' => $balanceUrl, 'color' => 'primary'])
View Balance
@endcomponent

Thanks,<br>
{{ mail_brand_name() }} Team
@endcomponent
