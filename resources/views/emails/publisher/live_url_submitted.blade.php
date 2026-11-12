@component('mail::message')
Hello,

The guest post is live and ready for your review. Please check the published article, then approve it or request corrections from the order page.

@include('emails.partials.mail-article-live', ['articleLive' => $articleLive ?? null])
@endcomponent
