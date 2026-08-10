@php
    $brand = config('email_notifications.brand', []);
    $logo = mail_brand_logo_url();
    $siteUrl = $brand['website_url'] ?? config('app.url');
    $support = $brand['support_email'] ?? null;
    $social = array_filter($brand['social'] ?? []);
@endphp
<x-mail::layout>
{{-- Header --}}
    <x-slot:header>
<x-mail::header :url="$siteUrl">
<img src="{{ $logo }}" class="logo" width="240" height="52" alt="{{ $brand['name'] ?? config('app.name') }}" style="display:block;margin:12px auto 8px;max-height:52px;max-width:260px;width:auto;height:auto;border:0;">
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if($support)
Need help? Contact us at [{{ $support }}](mailto:{{ $support }})
@endif

@if(!empty($social))
@foreach($social as $network => $url)
[{{ ucfirst($network) }}]({{ $url }}){{ !$loop->last ? ' · ' : '' }}
@endforeach
@endif

[{{ $brand['name'] ?? config('app.name') }}]({{ $siteUrl }})

{{ $brand['copyright'] ?? ('© ' . date('Y') . ' ' . config('app.name') . '. All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
