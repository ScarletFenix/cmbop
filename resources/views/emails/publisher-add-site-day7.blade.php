@component('mail::message')
# Finish setup — add your website

Hi {{ $firstName }},

It's been a week since you joined as a publisher, and you still haven't listed a website.

Without a site in the catalog, advertisers can't order placements from you — and you won't earn through your publisher wallet.

**What to do next**
1. Open **My Websites**
2. Add your domain, niche, and guest-post price
3. Complete verification so your listing can go live

@component('mail::button', ['url' => $websitesUrl])
Add your website now
@endcomponent

You can turn off marketing emails anytime in notification settings.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
