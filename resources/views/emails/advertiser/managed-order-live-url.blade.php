@component('mail::message')
Hello,

{{ $bodyCopy }}

@include('emails.partials.mail-article-live', ['articleLive' => $articleLive ?? null])

@include('emails.partials.mail-support', ['supportLine' => $supportLine ?? null, 'intent' => 'order'])
@endcomponent
