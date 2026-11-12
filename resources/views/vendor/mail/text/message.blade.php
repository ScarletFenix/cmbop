@php
    $mailTheme = $mailTheme
        ?? (app()->bound('transactional.mailTheme') ? app('transactional.mailTheme') : null)
        ?? \App\Support\TransactionalMailTheme::fromViewData(get_defined_vars());
@endphp
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$mailTheme->siteUrl">
{{ $mailTheme->brandName }}
</x-mail::header>
</x-slot:header>

@if($mailTheme->preheader)
{{ $mailTheme->preheader }}

@endif
{{ $mailTheme->pillLabel }}
{{ $mailTheme->heading }}
@if($mailTheme->subline)
{{ $mailTheme->subline }}
@endif
@if($mailTheme->showProgress)
@foreach(\App\Support\TransactionalMailTheme::PROGRESS_STEPS as $i => $label)
{{ $i + 1 === $mailTheme->currentStep ? '['.$label.']' : $label }}{{ $loop->last ? '' : ' > ' }}
@endforeach

@endif
{{ $slot }}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
Questions? Just reply to this email or write to {{ $mailTheme->supportEmail }}

SEOLinkBuildings · European guest post marketplace · 1000+ publishers

Help center: {{ $mailTheme->helpUrl }}
Notification settings: {{ $mailTheme->notificationSettingsUrl }}
@if($mailTheme->isMarketing && filled($mailTheme->unsubscribeUrl))
Unsubscribe: {{ $mailTheme->unsubscribeUrl }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
