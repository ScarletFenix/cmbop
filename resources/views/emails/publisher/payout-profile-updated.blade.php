@component('mail::message')
@php
    $withdrawUrl = $withdrawUrl ?? rtrim(function_exists('brand_public_origin') ? brand_public_origin() : url('/'), '/').'/publisher/withdraw';
@endphp
# Payout details updated

Dear {{ $userName }},

Our support team has updated your locked payout details for {{ strtoupper($method) }}.

For security, publishers cannot change payout methods themselves after the first confirmation. If you did not request this change, contact us immediately at {{ $supportEmail }}.

@component('mail::button', ['url' => $withdrawUrl])
View withdraw page
@endcomponent

Thanks,<br>
{{ mail_brand_name() }} Team
@endcomponent
