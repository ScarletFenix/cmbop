{{-- resources/views/emails/site-owner-order-notification.blade.php --}}
@component('mail::message')
<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>

Hello {{ $publisherName }},

A new order has been placed for your site **{{ $site->site_name }}**.

## Order Summary

**Order Numbers:** {{ $orderNumbers }}
**Order Count:** {{ $orderCount }} item(s)
**Total Amount:** €{{ number_format($totalAmount, 2) }}

@component('mail::button', ['url' => url('/publisher/sites')])
View Your Sites
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent