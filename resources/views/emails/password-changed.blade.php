@component('mail::message')
# Your password was changed, {{ $firstName }}

Someone (hopefully you) changed the password for your **{{ $brand['name'] ?? config('app.name') }}** account{{ !empty($changedAt) ? ' on '.$changedAt : '' }}.

If you made this change, no further action is needed. You can review your account anytime:

@component('mail::button', ['url' => $profileUrl])
Open Profile
@endcomponent

If you did **not** change your password, reset it now and contact support. This email never includes your password.

@component('mail::button', ['url' => $resetUrl])
Reset password
@endcomponent

Prefer to sign in first? [Sign in here]({{ $loginUrl }})

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
