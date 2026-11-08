@component('mail::message')
@php
    $addFundsUrl = $addFundsUrl ?? rtrim(function_exists('brand_public_origin') ? brand_public_origin() : url('/'), '/').'/advertiser/add-funds';
@endphp
# Deposit Request Update

Dear {{ $deposit->user?->name ?? 'Advertiser' }},

We regret to inform you that your deposit request has been rejected.

## Deposit Details:

- Amount: €{{ number_format($deposit->amount, 2) }}
- Reference Code: {{ $deposit->reference_code }}
- Rejected At: {{ optional($deposit->rejected_at)->format('M d, Y H:i') ?? now()->format('M d, Y H:i') }}

@if($deposit->admin_notes)
## Admin Notes:
{{ $deposit->admin_notes }}
@endif

If you believe this is an error, please contact our support team.

@component('mail::button', ['url' => $addFundsUrl])
Try Again
@endcomponent

Thanks,<br>
{{ mail_brand_name() }} Team
@endcomponent