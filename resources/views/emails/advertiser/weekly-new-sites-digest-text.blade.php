@php
    $cardCount = $cards->count();
    $support = \App\Support\MailNotificationPresenter::supportEmail() ?: 'support@seolinkbuildings.com';
    $matchedPairs = collect($cards)
        ->map(function ($card) {
            $niche = trim((string) ($card['niche'] ?? ''));
            $country = trim((string) ($card['country_label'] ?? ''));
            if ($niche !== '' && $country !== '') {
                return $niche.' · '.$country;
            }

            return $niche !== '' ? $niche : $country;
        })
        ->filter()
        ->unique()
        ->values();
@endphp
New sites in your niches

Hello,

Here {{ $cardCount === 1 ? 'is a newly listed site' : 'are '.$cardCount.' newly listed sites' }} that match placements you have paid for.
@if($matchedPairs->isNotEmpty())

Matched on: {{ $matchedPairs->implode(' | ') }}
@endif

@foreach($cards as $card)
{{ $card['domain'] }}
{{ $card['niche'] ?? '' }}@if(!empty($card['country_label'])) · {{ $card['country_label'] }}@endif

@if(($card['da'] ?? null) !== null)
DA {{ $card['da'] }}
@endif
@if(($card['dr'] ?? null) !== null)
DR {{ $card['dr'] }}
@endif
@if(($card['price'] ?? null) !== null)
from €{{ number_format((float) $card['price'], 2) }}
@endif
View site: {{ $card['details_url'] }}

@endforeach
@if(count($browseLinks) === 1)
Browse matching sites: {{ $browseLinks[0]['url'] }}
@elseif(count($browseLinks) > 1)
Browse matching sites:
@foreach($browseLinks as $link)
- {{ $link['niche'] }} in {{ $link['country_label'] }}: {{ $link['url'] }}
@endforeach
@endif

Questions? Just reply to this email or write to {{ $support }}

SEOLinkBuildings · European guest post marketplace · 1000+ publishers

Help center: {{ \App\Support\TransactionalMailTheme::publicPath('/faq') }}
Notification settings: {{ $preferencesUrl }}
Unsubscribe: {{ $unsubscribeUrl }}
