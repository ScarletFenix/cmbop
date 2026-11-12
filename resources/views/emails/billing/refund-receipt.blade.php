@component('mail::message')
# Refund Receipt

Hello,

A refund has been processed for your order.

| | |
|:--|:--|
| Refund receipt | {{ $refund->invoice_number }} |
| Refund amount | {{ \App\Support\MailNotificationPresenter::euro((float) $refund->total_amount, (string) $symbol) }} {{ $refund->currency }} |
| Refund date | {{ \App\Support\MailNotificationPresenter::formatDateTime($refund->invoice_date) }} |
| Transaction ID | {{ $refund->transaction_id }} |
| Original invoice | {{ $originalInvoice?->invoice_number ?? data_get($refund->meta, 'original_invoice', '—') }} |
| Reason | {{ $reason }} |

Your refund receipt PDF is attached.

@component('mail::button', ['url' => $downloadUrl, 'color' => 'primary'])
Download Refund Receipt
@endcomponent

@component('mail::button', ['url' => $ordersUrl])
View Orders
@endcomponent

@endcomponent
