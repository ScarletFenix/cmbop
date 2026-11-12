@component('mail::message')
Hello,

Your payment has been confirmed.

@include('emails.partials.mail-details-table', ['rows' => $detailRows ?? []])

@foreach($itemCards ?? [] as $card)
@include('emails.partials.mail-item-card', ['card' => $card])
@endforeach

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
View My Orders
@endcomponent
@endcomponent
