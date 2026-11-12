@component('mail::message')
# {{ $monthLabel }} spending summary

Hello,

Your monthly spending overview is ready.

| Metric | Value |
|--------|-------|
| Total spend | {{ \App\Support\MailNotificationPresenter::euro((float) ($payload['spend'] ?? 0)) }} |
| Orders | {{ $payload['orders'] ?? 0 }} |
| Avg order value | {{ \App\Support\MailNotificationPresenter::euro((float) ($payload['aov'] ?? 0)) }} |

@component('mail::button', ['url' => $ctaUrl])
{{ $ctaLabel }}
@endcomponent

Thanks,<br>
{{ $brand['name'] ?? config('app.name') }}
@endcomponent
