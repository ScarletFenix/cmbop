@component('mail::message')
# {{ $viaPaypal ? 'Refund sent to PayPal' : 'Refund credited' }}

Dear {{ $advertiser->name }},

Your link-removed dispute for order **#{{ $dispute->order?->order_number ?? $dispute->order_id }}** was **upheld**.

@if($viaPaypal)
**€{{ number_format($credited, 2) }}** was refunded to your PayPal account.
@else
**€{{ number_format($credited, 2) }}** has been credited back to your advertiser wallet.
@endif

@if($dispute->admin_notes)
**Notes:** {{ $dispute->admin_notes }}
@endif

@component('mail::button', ['url' => route('advertiser.balance')])
View balance
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
