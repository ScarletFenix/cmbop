@component('mail::message')
# Withdrawal Request {{ ucfirst($newStatus) }}

Dear {{ $withdrawal->user->name }},

Your withdrawal request has been **{{ ucfirst($newStatus) }}**.

## Request Details:

- **Request Date:** {{ $withdrawal->created_at->format('F j, Y') }}
- **Requested Amount:** €{{ number_format((float) $withdrawal->amount, 2) }}
@if((float) ($withdrawal->fee ?? 0) > 0)
- **Platform Fee:** -€{{ number_format((float) $withdrawal->fee, 2) }}
- **Net Payout:** €{{ number_format((float) ($withdrawal->net_amount ?? ((float) $withdrawal->amount - (float) $withdrawal->fee)), 2) }}
@endif
- **Payment Method:** {{ \App\Models\Invoice::paymentMethodLabel($withdrawal->payment_method) }}

@if($notes)
## Admin Notes:

{{ $notes }}
@endif

@if($newStatus == 'completed')
@php
    $netPaid = (float) ($withdrawal->net_amount ?? ((float) $withdrawal->amount - (float) ($withdrawal->fee ?? 0)));
@endphp
The amount of **€{{ number_format($netPaid, 2) }}** has been sent to your {{ \App\Models\Invoice::paymentMethodLabel($withdrawal->payment_method) }} account.

@if(!empty($statementUrl))
@component('mail::button', ['url' => $statementUrl])
Download payout statement
@endcomponent
@endif

@elseif($newStatus == 'cancelled')
The amount of **€{{ number_format((float) $withdrawal->amount, 2) }}** has been refunded to your wallet balance.

@elseif($newStatus == 'processing')
Your withdrawal request is now being processed. You will be notified once it's completed.

@endif

@component('mail::button', ['url' => route('publisher.withdraw')])
View Withdrawals
@endcomponent

If you have any questions, please contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
