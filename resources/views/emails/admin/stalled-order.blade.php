@component('mail::message')
# Order needs attention

Order **#{{ $order->order_number }}** has run through the automated reminder cadence without moving.

@component('mail::table')
| | |
| :--- | :--- |
| Stage | {{ $track === 'accept' ? 'Not accepted' : 'Not published' }}, reminder {{ $stage }} |
| Late by | {{ $days }} day(s) |
| Site | {{ $siteName }} |
| Publisher | {{ $publisher?->name ?? 'unknown' }}{{ $publisher?->email ? ' ('.$publisher->email.')' : '' }} |
| Order value | €{{ number_format((float) $order->total_amount, 2) }} |
@endcomponent

@component('mail::button', ['url' => $adminUrl])
Open in admin
@endcomponent

The publisher has had every reminder in the cadence. If they do not respond, refund the advertiser from the order so the funds return to their wallet balance and they can order elsewhere.

{{ config('app.name') }}
@endcomponent
