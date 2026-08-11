@component('mail::message')
# {{ $kind === 'low_balance' ? 'Low balance alert' : 'Spend budget update' }}

Hi {{ $user->name }},

@if($kind === 'warn')
You have used **{{ number_format((float) ($status['percent'] ?? 0), 1) }}%** of your
**€{{ number_format((float) ($status['monthly_limit'] ?? 0), 2) }}** monthly spend budget
(including in-progress orders).
@elseif($kind === 'hit')
You reached your **€{{ number_format((float) ($status['monthly_limit'] ?? 0), 2) }}** monthly
spend budget (including in-progress orders). Checkout is **not** blocked — this is a soft alert.
@else
Your spendable balance (**€{{ number_format((float) ($status['spendable'] ?? 0), 2) }}**)
is below your alert threshold of
**€{{ number_format((float) ($status['low_balance_threshold'] ?? 0), 2) }}**.
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
