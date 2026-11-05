@component('mail::message')
# Order confirmed · Managed by SLB

Hello {{ $order->user?->name ?? 'there' }},

{{ $bodyCopy }}

**Order:** #{{ $order->order_number }}

@component('mail::button', ['url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id])])
Open My Orders
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
