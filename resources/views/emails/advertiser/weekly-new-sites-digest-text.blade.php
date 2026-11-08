@php
    $cardCount = $cards->count();
@endphp
New sites in your niches

Hi {{ $firstName }}, here {{ $cardCount === 1 ? 'is a newly listed site' : 'are '.$cardCount.' newly listed sites' }} that match placements you have paid for.

@foreach($cards as $card)
{{ $card['domain'] }}
{{ $card['niche'] }}@if(!empty($card['country_label'])) · {{ $card['country_label'] }}@endif

@if($card['da'] !== null)
DA {{ $card['da'] }}
@endif
@if($card['dr'] !== null)
DR {{ $card['dr'] }}
@endif
@if($card['traffic'] !== null)
Organic traffic {{ number_format((int) $card['traffic']) }}
@endif
@if(!empty($card['metrics_checked_at']))
Metrics checked {{ $card['metrics_checked_at'] }}
@endif
@if($card['price'] !== null)
Price: €{{ number_format((float) $card['price'], 2) }}
@endif
View site details: {{ $card['details_url'] }}

@endforeach
@if(count($browseLinks) === 1)
Browse matching sites ({{ $browseLinks[0]['niche'] }} in {{ $browseLinks[0]['country_label'] }}): {{ $browseLinks[0]['url'] }}
@elseif(count($browseLinks) > 1)
Browse matching sites:
@foreach($browseLinks as $link)
- {{ $link['niche'] }} in {{ $link['country_label'] }}: {{ $link['url'] }}
@endforeach
@endif

{{ $legalName }}
{{ $legalFooter }}
@if(!empty($legalAddress))
{{ $legalAddress }}
@endif

Manage new-site email preferences: {{ $preferencesUrl }}
Unsubscribe from this digest: {{ $unsubscribeUrl }}
