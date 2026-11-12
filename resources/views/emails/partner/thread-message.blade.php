@component('mail::message')
Hello,

A new message arrived on order **{{ $orderNumber }}** ({{ $domain }}).

@if(!empty($preview))
{{ $preview }}
@endif

Open the order to reply. Replies by email are not delivered.

@component('mail::button', ['url' => $actionUrl])
Open order
@endcomponent

SEOLinkBuildings partner desk
@endcomponent
