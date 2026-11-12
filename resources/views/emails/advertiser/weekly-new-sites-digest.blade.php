@extends('emails.layouts.transactional')

@section('body')
@php
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
@if($matchedPairs->isNotEmpty())
<p style="margin:0 0 14px 0;color:#1a585e;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:700;line-height:1.4;">Matched on: {{ $matchedPairs->implode(' | ') }}</p>
@endif
@foreach($cards as $card)
    @include('emails.partials.mail-site-card', ['card' => $card])
@endforeach
@if(count($browseLinks) === 1)
<table align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:4px 0 0 0;font-family:Arial,Helvetica,sans-serif;">
<tr>
<td align="center" style="padding:0;">
<a class="mail-btn" href="{{ $browseLinks[0]['url'] }}" style="display:inline-block;background-color:#1a585e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 18px;border-radius:8px;font-family:Arial,Helvetica,sans-serif;">Browse matching sites</a>
</td>
</tr>
</table>
@elseif(count($browseLinks) > 1)
    <p style="margin:0 0 10px 0;font-size:14px;color:#1a585e;font-weight:700;font-family:Arial,Helvetica,sans-serif;text-align:center;">Browse matching sites</p>
    @foreach($browseLinks as $link)
        <p style="margin:0 0 8px 0;font-family:Arial,Helvetica,sans-serif;text-align:center;">
            <a href="{{ $link['url'] }}" style="color:#1a585e;font-size:13px;">{{ $link['niche'] }} in {{ $link['country_label'] }}</a>
        </p>
    @endforeach
@endif
@endsection
