@component('mail::message')

<div style="text-align:center; margin-bottom:20px;">
    <img src="https://seolinkbuildings.com/assets/img/logo1.png" alt="Seolinkbuildings Logo" width="150" style="display:block; margin:0 auto;">
</div>

# New Site Submitted for Review

A new site has been submitted by **{{ $publisherName }}** ({{ $publisherEmail }}) and requires your review.

## Site Details:

- **Site Name:** {{ $siteName }}
- **Site URL:** {{ $siteUrl }}
- **Category:** {{ $site->category }}
- **Price:** €{{ number_format($site->price, 2) }}
- **DA/DR:** {{ $site->da }}/{{ $site->dr }}
- **Traffic:** {{ number_format($site->traffic) }} monthly


Review Site


Thanks,<br>
{{ config('app.name') }}

@endcomponent