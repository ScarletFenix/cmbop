@component('mail::message')
Hello,

@include('emails.partials.mail-details-table', ['rows' => $detailRows ?? []])

The publisher has accepted your order and will start working on it.

@component('mail::button', ['url' => $ctaUrl ?? rtrim(app_public_url(), '/').route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false)])
View My Orders
@endcomponent
@endcomponent
