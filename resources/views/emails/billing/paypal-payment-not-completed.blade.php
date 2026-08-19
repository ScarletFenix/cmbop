@component('mail::message')
@if($pending)
# PayPal payment is under review
@else
# PayPal payment was not completed
@endif

Dear {{ $user->name ?? 'Advertiser' }},

@if($pending)
PayPal is still reviewing this payment. No guest-post order has been created and your wallet has not been credited yet. We will email you again when the payment completes or fails.
@elseif($reason === 'cancelled')
You cancelled the PayPal checkout before the payment finished. No charge was captured.
@elseif($reason === 'denied' || $reason === 'declined')
PayPal declined or denied this payment. No guest-post order was created and your wallet was not credited.
@else
We could not complete this PayPal payment. No guest-post order was created and your wallet was not credited.
@endif

## Payment details

- **Reference:** {{ $referenceCode }}
- **Type:** {{ $kind === 'deposit' ? 'Wallet top-up' : 'Checkout' }}

@if(! $pending)
You can start a new PayPal payment whenever you are ready.
@endif

@component('mail::button', ['url' => $retryUrl, 'color' => 'primary'])
{{ $retryLabel }}
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
