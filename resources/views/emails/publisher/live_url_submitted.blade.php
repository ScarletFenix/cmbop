@component('mail::message')
Hello {{ $firstName ?? 'there' }},

The guest post is live and ready for your review. Please check the published article, then approve it or request corrections from the order page.

@include('emails.partials.mail-article-live', ['articleLive' => $articleLive ?? null])

@php
    $mailItem = $orderItem ?? null;
@endphp
@if($mailItem && $mailItem->hasHomepagePlacement())
Homepage placement: {{ (int) $mailItem->homepage_days }} day{{ (int) $mailItem->homepage_days === 1 ? '' : 's' }}
@endif

@if($mailItem && $mailItem->offersSocialPromotion())
Social promotion: {{ collect($mailItem->enabledSocialChannels())->map(fn ($c) => $mailItem->socialChannelLabel($c))->implode(', ') }} (included)
@foreach($mailItem->socialPostUrls() as $channel => $url)
@php $safeSocial = \App\Support\MailNotificationPresenter::safeLiveUrl($url); @endphp
@if($safeSocial)
- {{ $mailItem->socialChannelLabel($channel) }}: <a href="{{ $safeSocial }}">{{ $safeSocial }}</a>
@endif
@endforeach
@endif

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])
@endcomponent
