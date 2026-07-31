@component('mail::message')
# {{ $headline }}

Hello {{ $adminFirstName }},

**{{ $newUser->name }}** just joined the platform as a **{{ $roleLabel }}**.

| Detail | Value |
|--------|-------|
| **Name** | {{ $newUser->name }} |
| **Email** | {{ $newUser->email }} |
| **Role** | {{ $roleLabel }} |
| **Registered** | {{ optional($newUser->created_at)->format('M j, Y g:i A') ?? now()->format('M j, Y g:i A') }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
