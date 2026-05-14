@component('mail::message')

<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>


Hello {{ $receiverName }},

You have received a new message from **{{ $sender->name }}** ({{ $senderType }}) regarding order **#{{ $order->order_number }}**.

## Message:
> {{ $message }}

@component('mail::button', ['url' => $url])
View Order & Reply
@endcomponent

Best regards,<br>
{{ config('app.name') }} Team

@endcomponent