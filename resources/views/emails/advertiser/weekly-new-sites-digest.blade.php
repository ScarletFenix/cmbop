@extends('emails.layouts.transactional')

@section('body')
@php $cardCount = $cards->count(); @endphp
<p style="margin:0 0 16px 0;color:#334155;font-size:15px;line-height:1.5;">
Hi {{ $firstName }}, here {{ $cardCount === 1 ? 'is a newly listed site' : 'are '.$cardCount.' newly listed sites' }} that match placements you have paid for.
</p>
@foreach($cards as $card)
    @include('emails.partials.mail-site-card', ['card' => $card])
@endforeach
@if(count($browseLinks) === 1)
    <p style="margin:8px 0 0 0;">
        <a class="mail-btn" href="{{ $browseLinks[0]['url'] }}" style="display:inline-block;background-color:#1a585e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 18px;border-radius:8px;">Browse matching sites</a>
    </p>
    <p style="margin:10px 0 0 0;font-size:12px;color:#64748b;">{{ $browseLinks[0]['niche'] }} in {{ $browseLinks[0]['country_label'] }}</p>
@elseif(count($browseLinks) > 1)
    <p style="margin:0 0 10px 0;font-size:14px;color:#1a585e;font-weight:700;">Browse matching sites</p>
    @foreach($browseLinks as $link)
        <p style="margin:0 0 8px 0;">
            <a href="{{ $link['url'] }}" style="color:#1a585e;font-size:13px;">{{ $link['niche'] }} in {{ $link['country_label'] }}</a>
        </p>
    @endforeach
@endif
@endsection
