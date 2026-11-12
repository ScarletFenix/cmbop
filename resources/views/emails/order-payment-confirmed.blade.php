@component('mail::message')
Hello,

Great news! Your payment for Order #{{ $order->order_number }} has been confirmed.

@include('emails.partials.mail-details-table', ['rows' => $detailRows ?? []])

@foreach($itemCards ?? [] as $card)
@include('emails.partials.mail-item-card', ['card' => $card])
@endforeach

Your order is now being processed. You can track your order status from your dashboard.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
View My Orders
@endcomponent
@endcomponent
