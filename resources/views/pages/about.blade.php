@extends('layouts.app')

@section('title', __('messages.meta_about_title'))
@section('description', __('messages.meta_about_description'))
@section('canonical', localized_url('about'))

@php
    $company = config('billing.company', []);
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'AboutPage',
    'name' => __('messages.meta_about_title'),
    'url' => localized_url('about'),
    'description' => __('messages.meta_about_description'),
    'mainEntity' => [
        '@type' => 'Organization',
        'name' => 'SEOLinkBuildings',
        'legalName' => $company['legal_name'] ?? 'SEOLinkBuildings Partners with (Topurlz LTD)',
        'url' => url('/'),
        'email' => $company['support_email'] ?? 'support@seolinkbuildings.com',
        'identifier' => $company['registration_no'] ?? '16607074',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => '20 Wenlock Road',
            'addressLocality' => 'London',
            'postalCode' => 'N1 7GU',
            'addressCountry' => 'GB',
        ],
        'sameAs' => [
            'https://www.linkedin.com/company/seolinkbuildings',
        ],
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.about_page_kicker'),
    'title' => __('messages.about_page_title'),
    'subtitle' => __('messages.about_page_subtitle'),
])

<div class="container py-5" style="max-width: 860px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_about'), 'url' => localized_url('about')],
        ],
    ])
    <div class="row g-4">
        <div class="col-md-6">
            <h2 class="h4" style="color:#1a585e;">{{ __('messages.about_page_mission_title') }}</h2>
            <p class="text-muted">{{ __('messages.about_page_mission_body') }}</p>
        </div>
        <div class="col-md-6">
            <h2 class="h4" style="color:#1a585e;">{{ __('messages.about_page_approach_title') }}</h2>
            <p class="text-muted">{{ __('messages.about_page_approach_body') }}</p>
        </div>
    </div>

    <div class="mt-5 p-4 rounded-3" style="background:#f7fafb; border:1px solid #e2e8f0;">
        <h2 class="h4 mb-3" style="color:#1a585e;">{{ __('messages.about_page_company_title') }}</h2>
        <p class="text-muted mb-3">{{ __('messages.about_page_company_body') }}</p>
        <ul class="list-unstyled text-muted mb-0 small">
            <li class="mb-2"><strong>{{ __('messages.about_page_legal_label') }}:</strong> {{ $company['legal_name'] ?? 'SEOLinkBuildings Partners with (Topurlz LTD)' }}</li>
            <li class="mb-2"><strong>{{ __('messages.about_page_reg_label') }}:</strong> {{ $company['registration_no'] ?? '16607074' }}</li>
            <li class="mb-2"><strong>{{ __('messages.about_page_address_label') }}:</strong> {{ implode(', ', $company['address_lines'] ?? ['20 Wenlock Road, London, England, N1 7GU']) }}</li>
            <li class="mb-2"><strong>{{ __('messages.about_page_email_label') }}:</strong> <a href="mailto:{{ $company['support_email'] ?? 'support@seolinkbuildings.com' }}">{{ $company['support_email'] ?? 'support@seolinkbuildings.com' }}</a></li>
            <li><strong>{{ __('messages.about_page_markets_label') }}:</strong> {{ __('messages.about_page_markets_body') }}</li>
        </ul>
    </div>

    <div class="text-center mt-5">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">{{ __('messages.get_started') }}</a>
    </div>
</div>
@endsection
