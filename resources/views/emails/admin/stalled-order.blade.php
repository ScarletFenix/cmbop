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

The publisher has had every reminder in the cadence. If they do not respond, refund the advertiser from the order
@if(($order->payment_method ?? '') === 'paypal')
so the PayPal capture returns to the buyer (do not credit the wallet again) and they can order elsewhere.
@else
so the funds return to their wallet balance and they can order elsewhere.
@endif

{{ config('app.name') }}
@endcomponent
