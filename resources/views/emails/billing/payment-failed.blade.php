@component('mail::message')
# Payment Failed

Hello,

We could not verify your payment for order #{{ $order?->order_number ?? $document->order_number }}.

| | |
|:--|:--|
| Reason | {{ $reason }} |
| Transaction reference | {{ $document->transaction_id }} |
| Attempted amount | {{ \App\Support\MailNotificationPresenter::euro((float) $document->total_amount, (string) $symbol) }} {{ $document->currency }} |
| Attempt date | {{ optional($document->invoice_date)->format('F j, Y g:i A') }} |

### Suggested next steps
1. Open your orders and click Pay again for this failed card payment.
2. Check that your card has sufficient funds, or try a different card.
3. Contact support if the problem continues.

@component('mail::button', ['url' => $retryUrl, 'color' => 'primary'])
Pay again
@endcomponent

No tax invoice was generated for this failed attempt.

@endcomponent
