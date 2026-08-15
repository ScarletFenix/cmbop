@component('mail::message')
# Hello {{ $firstName }},

{!! $bodyHtml !!}

@if(!empty($ctaUrl) && !empty($ctaLabel))
@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent
@endif

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}

@component('mail::subcopy')
You received this email because you have an account at {{ $brand['name'] ?? config('app.name') }}.
[Unsubscribe from marketing emails]({{ $unsubscribeUrl }})
@endcomponent
@endcomponent
