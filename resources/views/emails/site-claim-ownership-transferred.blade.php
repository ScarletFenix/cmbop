@component('mail::message')
# Listing ownership transferred

Our team approved an ownership claim for **{{ $siteName }}**. This listing is no longer linked to your publisher account.

If you believe this was a mistake, contact support with the site domain and any ownership proof you have.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
