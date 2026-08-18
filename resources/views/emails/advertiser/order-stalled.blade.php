@component('mail::message')
# We are chasing your order

Hi {{ $firstName }},

Your guest post on **{{ $siteName }}** (order **#{{ $order->order_number }}**) was due on **{{ $dueAt->format('j M') }}** and is now {{ $daysOverdue }} day(s) late. We would rather tell you than leave you wondering.

Here is where it stands:

- The publisher has been reminded and our team is following up directly.
- Your payment is still held in escrow. It has not been released.
- If the publisher does not deliver, we will refund the full amount {{ ($order->payment_method ?? '') === 'paypal' ? 'to the PayPal account that paid' : 'to your wallet balance' }} so you can order on another site.

@component('mail::button', ['url' => $ordersUrl])
View the order
@endcomponent

You can message the publisher directly in the order chat, and if you would rather not wait, reply to this email and we will arrange the refund.

Sorry for the delay,<br>
{{ config('app.name') }}
@endcomponent
