@component('mail::message')
# Payment Successful

Hello,

Your payment was successful. Your invoice is attached to this email.

## Payment confirmation

| | |
|:--|:--|
| Invoice | {{ $invoice->invoice_number }} |
| Order | #{{ $order?->order_number ?? $invoice->order_number }} |
| Amount paid | {{ \App\Support\MailNotificationPresenter::euro((float) $invoice->total_amount, (string) $symbol) }} {{ $invoice->currency }} |
| Payment method | {{ \App\Models\Invoice::paymentMethodLabel($invoice->payment_method) }} |
| Transaction ID | {{ $invoice->transaction_id }} |
| Paid at | {{ \App\Support\MailNotificationPresenter::formatDateTime($invoice->paid_at) }} |

## Order summary

@foreach(($order?->items ?? collect()) as $item)
- {{ $item->site_name }} — {{ \App\Support\MailNotificationPresenter::euro((float) $item->price, (string) $symbol) }}
@endforeach

@component('mail::button', ['url' => $viewOrderUrl, 'color' => 'primary'])
View Order
@endcomponent

@component('mail::button', ['url' => $downloadInvoiceUrl])
Download Invoice
@endcomponent

@component('mail::button', ['url' => $dashboardUrl])
Go to Dashboard
@endcomponent

Thank you for choosing {{ mail_brand_name() }}.

@endcomponent
