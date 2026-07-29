@extends('layouts.app')

@section('title', __('messages.meta_faq_title'))
@section('description', __('messages.meta_faq_description'))
@section('canonical', localized_url('faq'))

@php
    $faqEntities = [];
    foreach (range(1, 6) as $i) {
        $faqEntities[] = [
            '@type' => 'Question',
            'name' => __('messages.faq_q_'.$i),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => __('messages.faq_a_'.$i),
            ],
        ];
    }
@endphp

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => $faqEntities,
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.faq_kicker'),
    'title' => __('messages.faq_title'),
    'subtitle' => __('messages.faq_subtitle'),
])

<div class="container py-5" style="max-width: 800px;">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.nav_faq'), 'url' => localized_url('faq')],
        ],
    ])
    <div class="accordion" id="faqAccordion">
        @foreach(range(1, 6) as $i)
            <div class="accordion-item border-0 mb-3 shadow-sm rounded-3 overflow-hidden">
                <h2 class="accordion-header" id="faqHeading{{ $i }}">
                    <button class="accordion-button {{ $i > 1 ? 'collapsed' : '' }}" type="button"
                            data-bs-toggle="collapse" data-bs-target="#faqCollapse{{ $i }}"
                            aria-expanded="{{ $i === 1 ? 'true' : 'false' }}"
                            aria-controls="faqCollapse{{ $i }}">
                        {{ __('messages.faq_q_'.$i) }}
                    </button>
                </h2>
                <div id="faqCollapse{{ $i }}" class="accordion-collapse collapse {{ $i === 1 ? 'show' : '' }}"
                     aria-labelledby="faqHeading{{ $i }}" data-bs-parent="#faqAccordion">
                    <div class="accordion-body text-muted">
                        {{ __('messages.faq_a_'.$i) }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
