@component('mail::message')
Hello,

Your payout of **{{ $amountLabel }}** has been released.

Sign in to the partner dashboard to view Money. This email cannot be switched off.

@component('mail::button', ['url' => $actionUrl])
Sign in
@endcomponent

SEOLinkBuildings partner desk
@endcomponent
