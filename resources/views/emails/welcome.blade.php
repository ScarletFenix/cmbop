@component('mail::message')
# Welcome aboard, {{ $firstName }}!

Thanks for joining **{{ $brand['name'] ?? config('app.name') }}**.

@if(!empty($needsVerification))
Please verify your email address to activate your account and sign in.
@else
Your account is ready — explore verified publishers and place your first order whenever you’re ready.
@endif

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

@if(!empty($needsVerification))
If the button does not work, copy and paste this link into your browser:

[{{ $ctaUrl }}]({{ $ctaUrl }})

Already verified? [Sign in here]({{ $loginUrl }})
@else
Prefer to start from your dashboard? [Open dashboard]({{ $dashboardUrl }})
@endif

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
