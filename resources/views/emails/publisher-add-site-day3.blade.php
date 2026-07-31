@component('mail::message')
# List your first website

Hi {{ $firstName }},

You're registered as a publisher on **{{ $brand['name'] ?? config('app.name') }}**, but you haven't added a website yet.

Listing a site is how advertisers find you: set your niche, pricing, and availability, then start receiving guest-post orders with clear briefs and wallet payouts.

@component('mail::button', ['url' => $websitesUrl])
Add your website
@endcomponent

It only takes a few minutes — once your site is live in the catalog, you're ready for orders.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
