@component('mail::message')
# Your temporary password, {{ $firstName }}

You signed up with Google on **{{ $brand['name'] ?? config('app.name') }}**. We created a temporary password so you can also sign in with email and password (or change it later in Profile).

**Email:** {{ $email }}

**Temporary password:**

@component('mail::panel')
{{ $temporaryPassword }}
@endcomponent

Copy the password exactly (letters and numbers only — no spaces). Then [sign in here]({{ $loginUrl }}) with your email and this password.

To set your own password after signing in: open **Profile → Change Password**, enter this temporary password as **Current password**, then choose a new one.

@component('mail::button', ['url' => $loginUrl])
Sign in
@endcomponent

Please change this password after your first login and do not share it.

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }} Team
@endcomponent
