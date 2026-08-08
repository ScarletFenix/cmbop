@component('mail::message')
# A website is waiting for your acceptance

Hi {{ $publisherName }},

Our team added **{{ $domain }}** to your account. Please review it and **accept** it so it appears in your My Sites list.

After you accept:
- The site shows under **My Sites**
- You can verify ownership with the usual TXT file when you want the Verified badge
- Our team can activate it for the catalog when ready

@component('mail::button', ['url' => $acceptUrl])
Review & accept site
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
