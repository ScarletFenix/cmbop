@component('mail::message')
# Manual Payment Required

A customer has placed an order with manual payment.

Customer: {{ $customer->name }}
Total Amount: {{ \App\Support\MailNotificationPresenter::euro((float) $totalAmount) }}

Please review and confirm payment.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('admin.payments', [], false)])
View Payments
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent