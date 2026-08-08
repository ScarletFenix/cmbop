<x-mail::message>
# Your discount has ended

Hi {{ $publisher->name ?? 'there' }},

Your configured timed discount on **{{ $site->site_name }}**
@if($percent)
(−{{ rtrim(rtrim(number_format($percent, 2), '0'), '.') }}%)
@endif
has ended. Advertisers already saw the fee-floor effective rate while it was live.

@if($endedAt)
**Ended:** {{ $endedAt->timezone(config('app.timezone'))->format('d M Y H:i') }}
@endif

You can set a new timed discount anytime from your publisher websites catalog.

<x-mail::button :url="route('publisher.websites')">
Manage my websites
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
