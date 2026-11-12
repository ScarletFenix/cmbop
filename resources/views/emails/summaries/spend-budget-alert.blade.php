@component('mail::message')
# {{ $kind === 'low_balance' ? 'Low balance alert' : 'Spend budget update' }}

Hello,

@if($kind === 'warn')
You have used {{ number_format((float) ($status['percent'] ?? 0), 1) }}% of your
{{ \App\Support\MailNotificationPresenter::euro((float) ($status['monthly_limit'] ?? 0)) }} monthly spend budget
(including in-progress orders).
@elseif($kind === 'hit')
You reached your {{ \App\Support\MailNotificationPresenter::euro((float) ($status['monthly_limit'] ?? 0)) }} monthly
spend budget (including in-progress orders). Checkout is not blocked — this is a soft alert.
@else
Your spendable balance ({{ \App\Support\MailNotificationPresenter::euro((float) ($status['spendable'] ?? 0)) }})
is below your alert threshold of
{{ \App\Support\MailNotificationPresenter::euro((float) ($status['low_balance_threshold'] ?? 0)) }}.
@endif

@component('mail::button', ['url' => $analyticsUrl])
View spending
@endcomponent

@component('mail::button', ['url' => $addFundsUrl, 'color' => 'success'])
Add funds
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
