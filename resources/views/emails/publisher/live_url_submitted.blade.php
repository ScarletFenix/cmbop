@component('mail::message')
# Live URL Submitted

Dear Customer,

The publisher has submitted the live URL for your order **#{{ $order->order_number }}**.

## Order Details:

- **Site:** {{ $site->site_name }}
- **Order Number:** {{ $order->order_number }}
- **Reference Code:** {{ $order->reference_code }}

## Live URL:
<a href="{{ $liveUrl }}">{{ $liveUrl }}</a>

## Next Steps:

Please review the published content. Once you are satisfied, you can approve the order from your dashboard.

@component('mail::button', ['url' => route('advertiser.orders')])
Review Order
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent