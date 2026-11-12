@extends('emails.layouts.transactional')

@section('body')
@php
    $cardCount = $cards->count();
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
<p style="margin:0 0 12px 0;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;">Hello,</p>
<p style="margin:0 0 16px 0;color:#334155;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;">
Here {{ $cardCount === 1 ? 'is a newly listed site' : 'are '.$cardCount.' newly listed sites' }} that match placements you have paid for.
</p>
@if($matchedPairs->isNotEmpty())
<p style="margin:0 0 14px 0;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:1.4;">Matched on: {{ $matchedPairs->implode(' | ') }}</p>
@endif
@foreach($cards as $card)
    @include('emails.partials.mail-site-card', ['card' => $card])
@endforeach
@if(count($browseLinks) === 1)
    <p style="margin:8px 0 0 0;">
        <a class="mail-btn" href="{{ $browseLinks[0]['url'] }}" style="display:inline-block;background-color:#1a585e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 18px;border-radius:8px;font-family:Arial,Helvetica,sans-serif;">Browse matching sites</a>
    </p>
@elseif(count($browseLinks) > 1)
    <p style="margin:0 0 10px 0;font-size:14px;color:#1a585e;font-weight:700;font-family:Arial,Helvetica,sans-serif;">Browse matching sites</p>
    @foreach($browseLinks as $link)
        <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;">
            <a href="{{ $link['url'] }}" style="color:#1a585e;font-size:13px;">{{ $link['niche'] }} in {{ $link['country_label'] }}</a>
        </p>
    @endforeach
@endif
@endsection
