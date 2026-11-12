@component('mail::message')
Hello,

If you take no action in about {{ $hoursRemaining }} hour(s), the order will be auto-completed and the publisher will be paid.

@include('emails.partials.mail-article-live', ['articleLive' => $articleLive ?? null])
@endcomponent
