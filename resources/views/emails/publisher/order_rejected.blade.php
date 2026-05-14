@component('mail::message')
<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>
# Order Rejected

Dear Customer,

We regret to inform you that your order **#{{ $order->order_number }}** has been **rejected** by the publisher.

## Order Details:

- **Site:** {{ $site->site_name }}
- **Order Number:** {{ $order->order_number }}
- **Reference Code:** {{ $order->reference_code }}

## Reason for Rejection:
{{ $reason }}

## What's Next?

You can browse other publishers and place a new order.

@component('mail::button', ['url' => route('advertiser.catalog')])
Browse Publishers
@endcomponent

If you have any questions, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
@endcomponent