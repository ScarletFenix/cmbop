@component('mail::message')
# Wallet topped up — order not created

Dear {{ $user->name ?? 'Advertiser' }},

Your **{{ $methodLabel }}** payment of **€{{ number_format($amount, 2) }}** succeeded, but we could not create the guest-post order (the listing left the catalog, the amount did not match, or the checkout expired).

The full amount has been added to your advertiser wallet. You can use it on a new checkout, or contact support if you expected the original order.

## Payment details

- **Amount credited:** €{{ number_format($amount, 2) }}
- **Checkout reference:** {{ $referenceCode }}
- **Payment method:** {{ $methodLabel }}

## Your current balance

**€{{ number_format($walletBalance ?? 0, 2) }}**

@component('mail::button', ['url' => $balanceUrl, 'color' => 'primary'])
View Balance
@endcomponent

@component('mail::button', ['url' => $catalogUrl])
Browse catalog
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent
