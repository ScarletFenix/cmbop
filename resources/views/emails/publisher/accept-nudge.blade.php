@component('mail::message')
# {{ $stage >= 3 ? 'Your order is still waiting' : 'A paid order is waiting on you' }}

Hi {{ $firstName }},

An advertiser paid for a placement on **{{ $siteName }}** {{ $hoursWaiting }} hour(s) ago and order **#{{ $order->order_number }}** has not been accepted yet.

The money is already reserved. Accepting it starts your turnaround clock and puts **€{{ number_format($payout, 2) }}** on the way to your balance.

@component('mail::button', ['url' => $tasksUrl])
Accept the order
@endcomponent

@if($stage >= 3)
This is the third reminder, so our team has been copied. If the site is not taking orders right now, decline the order or pause the listing — that frees the advertiser to buy elsewhere instead of waiting.
@else
If you cannot take this one on, decline it so the advertiser can choose another site.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
