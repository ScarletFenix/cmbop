@component('mail::message')
@if($isInstant)
# Wallet topped up
@else
# Deposit Approved
@endif

Hello,

@if($isPaypal)
Your PayPal payment succeeded and {{ \App\Support\MailNotificationPresenter::euro((float) $deposit->amount) }} has been added to your wallet.
@elseif($isCard)
Your card payment succeeded and {{ \App\Support\MailNotificationPresenter::euro((float) $deposit->amount) }} has been added to your wallet.
@else
Your deposit request has been approved and the funds have been added to your wallet.
@if(!empty($approvalFxLine))
{{ $approvalFxLine }}
@endif
@endif

@if($receipt)
Your deposit receipt PDF is attached.
@endif

## Deposit Details:

- Amount: {{ \App\Support\MailNotificationPresenter::euro((float) $deposit->amount) }}
- Reference Code: {{ $deposit->reference_code }}
- Payment method: {{ \App\Models\Invoice::paymentMethodLabel($deposit->payment_method) }}
@if($isInstant)
- Credited At: {{ optional($deposit->approved_at ?? $deposit->paid_at)->format('M d, Y H:i') ?? now()->format('M d, Y H:i') }}
@else
- Approved At: {{ optional($deposit->approved_at)->format('M d, Y H:i') ?? now()->format('M d, Y H:i') }}
@endif
@if($receipt)
- Receipt: {{ $receipt->invoice_number }}
@endif

## Your Current Balance:

{{ \App\Support\MailNotificationPresenter::euro((float) ($walletBalance ?? 0)) }}

@component('mail::button', ['url' => $balanceUrl, 'color' => 'primary'])
View Balance
@endcomponent

@if(!empty($downloadReceiptUrl))
@component('mail::button', ['url' => $downloadReceiptUrl])
Download Receipt
@endcomponent
@endif

Thank you for using {{ mail_brand_name() }}!

Thanks,<br>
{{ mail_brand_name() }} Team
@endcomponent
