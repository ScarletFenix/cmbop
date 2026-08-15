@component('mail::message')
# @if($approved)
Article approved for publication
@else
Article needs changes
@endif

Hello {{ $firstName }},

@if($approved && !empty($result['approved_leftover']))
Your article **{{ $submission->title ?: $submission->original_filename }}** is approved. Continue the open order it is already attached to — use Pay again there, or start a new checkout to replace it.
@elseif($approved)
Your article **{{ $submission->title ?: $submission->original_filename }}** is approved. You can select websites and place an order from your Content Library.
@else
Your article **{{ $submission->title ?: $submission->original_filename }}** was saved to your Content Library, but it needs changes before you can order.

@if(!empty($result['message']))
**Reason:** {{ $result['message'] }}
@endif

@php
    $report = is_array($result['report'] ?? null) ? $result['report'] : [];
    $terms = scalar_list($result['matched_terms'] ?? ($report['matched_terms'] ?? []));
    $blockedUrls = scalar_list($result['blocked_urls'] ?? ($report['blocked_urls'] ?? []));
    $hints = scalar_list($report['fix_hints'] ?? []);
@endphp

@if(count($terms))
**Terms to remove or rewrite:** {{ implode(', ', array_slice($terms, 0, 12)) }}
@endif

@if(count($blockedUrls))
**Blocked links to remove:** {{ implode(', ', array_slice($blockedUrls, 0, 5)) }}
@endif

@if(count($hints))
@foreach($hints as $hint)
- {{ $hint }}
@endforeach
@endif

Open the article in Content Library to see highlighted text and links in the preview, then edit and resubmit.
@endif

@component('mail::button', ['url' => $libraryUrl])
@if($approved)
Open Content Library
@else
Fix article
@endif
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
