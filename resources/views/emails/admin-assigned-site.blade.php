@component('mail::message')
# A website is waiting for your acceptance

Hi {{ $publisherName }},

Our team added **{{ $domain }}** to your account. Please review it and **accept** it so it appears in your My Sites list.

After you accept:
- The site shows under **My Sites**
- Staff review the listing. The Verified badge is a separate TXT step
- Catalog Activate is not automatic

@component('mail::button', ['url' => $acceptUrl])
Review & accept site
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
