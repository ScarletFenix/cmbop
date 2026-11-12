@component('mail::message')
# Article ready for approval — order #{{ $orderNumber }}

Hello,

The written article for {{ $siteName }} is ready. Approve it, or request one free revision.

@component('mail::button', ['url' => $ordersUrl])
Review article
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
