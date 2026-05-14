@component('mail::message')
<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>
# Manual Payment Required

A customer has placed an order with manual payment.

**Customer:** {{ $customer->name }}
**Total Amount:** €{{ number_format($totalAmount, 2) }}

Please review and confirm payment.

@component('mail::button', ['url' => url('/admin/payments')])
View Payments
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent