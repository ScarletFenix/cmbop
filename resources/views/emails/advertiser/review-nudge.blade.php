@component('mail::message')
# Your link is live

Hi {{ $firstName }},

The publisher has submitted the live link for your guest post on **{{ $siteName }}** (order **#{{ $order->order_number }}**).

@if($liveUrl)
**Live URL:** [{{ $liveUrl }}]({{ $liveUrl }})
@endif

Worth a quick check: that the anchor text and target URL are right, the link is not marked nofollow when you paid for dofollow, and the article reads as agreed.

@component('mail::button', ['url' => $ordersUrl])
Check and approve
@endcomponent

If nothing needs changing you do not have to do anything — the order completes automatically on **{{ $autoCompletesAt->format('j M \a\t H:i') }}** and the publisher is paid. If something is wrong, request changes before then and the publisher has to fix it first.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
