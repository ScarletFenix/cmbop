@component('mail::message')
# Verify your email

Thanks for creating your account. Please verify your email address to activate login and start using the marketplace.

@component('mail::button', ['url' => $verifyUrl])
Click to verify
@endcomponent

This verification link expires in 60 minutes.

If you did not create an account, no further action is required.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
