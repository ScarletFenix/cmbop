@component('mail::message')
# Order Approved! 🎉

Dear Publisher,

Great news! The advertiser has **approved** the order for your site.

## Order Details:

- **Order Number:** #{{ $order->order_number }}
- **Site:** {{ $site->site_name }}
- **Reference Code:** {{ $order->reference_code }}

## Content Details:

- **Content Link:** <a href="{{ $orderItem->content_link }}">View Content</a>
- **Live URL:** <a href="{{ $orderItem->live_url }}">{{ $orderItem->live_url }}</a>

## Payment Details:

- **Base Price:** €{{ number_format($basePrice, 2) }}
@if($orderItem->additional_price > 0)
- **{{ ucfirst($orderItem->sensitive_type) }}:** +€{{ number_format($orderItem->additional_price, 2) }}
@endif
- **Total Amount:** €{{ number_format($orderItem->price, 2) }}

## What this means:

The advertiser has confirmed that the content is published correctly and meets their requirements. 
The payment for this order has been completed and will be credited to your account.

You can view all your approved orders in your publisher dashboard.

@component('mail::button', ['url' => route('publisher.tasks')])
View My Tasks
@endcomponent

Thank you for your quality work!

Thanks,<br>
{{ config('app.name') }}
@endcomponent