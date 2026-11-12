@php
    $mailTheme = app()->bound('transactional.mailTheme')
        ? app('transactional.mailTheme')
        : \App\Support\TransactionalMailTheme::fromViewData(get_defined_vars());
    app()->instance('transactional.mailTheme', $mailTheme);
    $brand = config('email_notifications.brand', []);
    $siteUrl = $mailTheme->siteUrl;
@endphp
<x-mail::layout :mail-theme="$mailTheme">
    <x-slot:header>
<x-mail::header :url="$siteUrl">
{{ $mailTheme->brandName }}
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
