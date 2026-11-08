@php
    $teal = '#1a585e';
    $grey = '#5c6b70';
    $line = '#d5dddf';
    $bg = '#f4f6f7';
    $cardCount = $cards->count();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New sites matching your placements</title>
</head>
<body style="margin:0;padding:0;background:{{ $bg }};color:{{ $grey }};font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $bg }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid {{ $line }};">
                <tr>
                    <td style="padding:24px 24px 8px 24px;">
                        @if(!empty($brand['logo_url']))
                            <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] ?? 'SEOLinkBuildings' }}" width="180" style="display:block;border:0;max-width:180px;height:auto;">
                        @else
                            <p style="margin:0;font-size:18px;font-weight:bold;color:{{ $teal }};">{{ $brand['name'] ?? 'SEOLinkBuildings' }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 24px 0 24px;">
                        <p style="margin:0 0 8px 0;font-size:20px;line-height:1.35;color:{{ $teal }};font-weight:bold;">New sites in your niches</p>
                        <p style="margin:0 0 20px 0;font-size:15px;line-height:1.5;color:{{ $grey }};">
                            Hi {{ $firstName }}, here {{ $cardCount === 1 ? 'is a newly listed site' : 'are '.$cardCount.' newly listed sites' }} that match placements you have paid for.
                        </p>
                    </td>
                </tr>
                @foreach($cards as $card)
                    <tr>
                        <td style="padding:0 24px 16px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {{ $line }};">
                                <tr>
                                    <td style="padding:0;background:#eef2f3;height:120px;text-align:center;vertical-align:middle;">
                                        @if(!empty($card['has_screenshot']) && !empty($card['screenshot_url']))
                                            <img src="{{ $card['screenshot_url'] }}" alt="Homepage preview of {{ $card['domain'] }}" width="552" style="display:block;width:100%;max-height:160px;object-fit:cover;border:0;">
                                        @else
                                            <p style="margin:16px;font-size:13px;color:{{ $grey }};">Preview card for {{ $card['domain'] }}</p>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px;">
                                        <p style="margin:0 0 6px 0;font-size:16px;font-weight:bold;color:{{ $teal }};">{{ $card['domain'] }}</p>
                                        <p style="margin:0 0 10px 0;font-size:13px;color:{{ $grey }};">
                                            {{ $card['niche'] }}
                                            @if(!empty($card['country_label']))
                                                · {{ $card['country_label'] }}
                                            @endif
                                        </p>
                                        <p style="margin:0 0 12px 0;font-size:13px;line-height:1.45;color:{{ $grey }};">
                                            @if($card['da'] !== null) DA {{ $card['da'] }} @endif
                                            @if($card['dr'] !== null) · DR {{ $card['dr'] }} @endif
                                            @if($card['traffic'] !== null) · Organic traffic {{ number_format((int) $card['traffic']) }} @endif
                                            @if(!empty($card['metrics_checked_at']))
                                                · Metrics checked {{ $card['metrics_checked_at'] }}
                                            @endif
                                        </p>
                                        @if($card['price'] !== null)
                                            <p style="margin:0 0 14px 0;font-size:15px;font-weight:bold;color:{{ $teal }};">€{{ number_format((float) $card['price'], 2) }}</p>
                                        @endif
                                        <a href="{{ $card['details_url'] }}" style="display:inline-block;background:{{ $teal }};color:#ffffff;text-decoration:none;font-size:13px;padding:10px 16px;">View site details</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:8px 24px 24px 24px;">
                        @if(count($browseLinks) === 1)
                            <a href="{{ $browseLinks[0]['url'] }}" style="display:inline-block;background:{{ $teal }};color:#ffffff;text-decoration:none;font-size:14px;padding:12px 18px;">Browse matching sites</a>
                            <p style="margin:10px 0 0 0;font-size:12px;color:{{ $grey }};">{{ $browseLinks[0]['niche'] }} in {{ $browseLinks[0]['country_label'] }}</p>
                        @elseif(count($browseLinks) > 1)
                            <p style="margin:0 0 10px 0;font-size:14px;color:{{ $teal }};font-weight:bold;">Browse matching sites</p>
                            @foreach($browseLinks as $link)
                                <p style="margin:0 0 8px 0;">
                                    <a href="{{ $link['url'] }}" style="color:{{ $teal }};font-size:13px;">{{ $link['niche'] }} in {{ $link['country_label'] }}</a>
                                </p>
                            @endforeach
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid {{ $line }};font-size:12px;line-height:1.5;color:{{ $grey }};">
                        <p style="margin:0 0 8px 0;">{{ $legalName }}</p>
                        <p style="margin:0 0 8px 0;">{{ $legalFooter }}</p>
                        @if(!empty($legalAddress))
                            <p style="margin:0 0 8px 0;">{{ $legalAddress }}</p>
                        @endif
                        <p style="margin:0 0 8px 0;">
                            <a href="{{ $preferencesUrl }}" style="color:{{ $teal }};">Manage new-site email preferences</a>
                            ·
                            <a href="{{ $unsubscribeUrl }}" style="color:{{ $teal }};">Unsubscribe from this digest</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
