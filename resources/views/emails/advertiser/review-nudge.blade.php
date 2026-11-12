@component('mail::message')
Hello,

@include('emails.partials.mail-article-live', ['articleLive' => $articleLive ?? null])
@endcomponent
