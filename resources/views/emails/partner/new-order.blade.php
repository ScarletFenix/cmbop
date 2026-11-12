@component('mail::message')
Hello,

A new order **{{ $orderNumber }}** is waiting on **{{ $domain }}**.

Open the order to accept, reject, or submit the live URL. Replies by email are not delivered.

@component('mail::button', ['url' => $actionUrl])
Open order
@endcomponent

SEOLinkBuildings partner desk
@endcomponent
