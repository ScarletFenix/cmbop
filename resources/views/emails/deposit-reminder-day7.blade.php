@component('mail::message')
# Your €20 credit is waiting

Hi {{ $firstName }},

You've been on **{{ $brand['name'] ?? config('app.name') }}** for a week. Your advertiser wallet already includes **€20 welcome credit** toward guest posts on verified publishers.

To place larger orders, add funds once (card, bank, or Wise). Your balance stays in EUR and you only spend when you checkout.

**Suggested next step:** browse the catalog, shortlist 2–3 sites, then add funds so checkout is one click.

@component('mail::button', ['url' => $catalogUrl])
Browse catalog
@endcomponent

Prefer to fund first? [Add funds]({{ $addFundsUrl }})

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
