@component('mail::message')
# Order approved

Dear Publisher,

Great news! The advertiser has approved the order for your site.

## Order Details:

- Order number: #{{ $order->order_number }}
- Website: {{ $site->site_name }}@if(!empty($site->domain)) ({{ $site->domain }})@endif

## Content Details:

@php
    $publisherContentLink = $orderItem instanceof \App\Models\OrderItem
        ? $orderItem->publisherContentLink()
        : null;
@endphp
@if($publisherContentLink)
- Content Link: <a href="{{ $publisherContentLink }}">View Content</a>
@endif
@php $approvedLive = \App\Support\MailNotificationPresenter::safeLiveUrl($orderItem->live_url ?? null); @endphp
@if($approvedLive)
- Live article URL: <a href="{{ $approvedLive }}">{{ $approvedLive }}</a>
@endif

## Payment Details:

- Base Price: {{ \App\Support\MailNotificationPresenter::euro((float) $basePrice) }}
@if($orderItem->additional_price > 0)
- {{ ucfirst($orderItem->sensitive_type) }}: +{{ \App\Support\MailNotificationPresenter::euro((float) $orderItem->additional_price) }}
@endif
@if($orderItem->hasHomepagePlacement())
- Homepage ({{ (int) $orderItem->homepage_days }} day{{ (int) $orderItem->homepage_days === 1 ? '' : 's' }}): @if((float) ($orderItem->homepage_price ?? 0) > 0)+{{ \App\Support\MailNotificationPresenter::euro((float) $orderItem->homepage_price) }}@else Free @endif
@endif
@if($orderItem->offersSocialPromotion())
- Social: {{ collect($orderItem->enabledSocialChannels())->map(fn ($c) => $orderItem->socialChannelLabel($c))->implode(', ') }} (included)
@endif
- Amount Credited: {{ \App\Support\MailNotificationPresenter::euro((float) $payoutAmount) }}

## What this means:

The advertiser has confirmed that the content is published correctly and meets their requirements. 
Your payout (listing price; platform fee excluded) has been credited to your publisher wallet.

You can view all your approved orders in your publisher dashboard.

@component('mail::button', ['url' => rtrim(app_public_url(), '/').route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false)])
View My Tasks
@endcomponent

Thank you for your quality work!

Thanks,<br>
{{ config('app.name') }}
@endcomponent