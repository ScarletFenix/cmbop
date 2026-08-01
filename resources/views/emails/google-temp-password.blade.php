@component('mail::message')
# Your temporary password, {{ $firstName }}

You signed up with Google on **{{ $brand['name'] ?? config('app.name') }}**. We created a temporary password so you can also sign in with email or change your password later.

**Email:** {{ $email }}

**Temporary password:** `{{ $temporaryPassword }}`

To set your own password: open **Profile → Change Password**, enter this temporary password as **Current password**, then choose a new one.

@component('mail::button', ['url' => $profileUrl])
Open Profile
@endcomponent

You can also [sign in here]({{ $loginUrl }}) with Google or with the email and temporary password above.

Please change this password after your first login and do not share it.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
