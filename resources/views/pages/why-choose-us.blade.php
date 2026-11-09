@extends('layouts.app')

@section('title', __('messages.meta_why_choose_title'))
@section('description', __('messages.meta_why_choose_description'))
@section('canonical', localized_url('why-choose-us'))

@section('content')
@include('components.marketing-page-hero', [
    'kicker' => __('messages.why_choose_kicker'),
    'title' => __('messages.why_choose_title'),
    'subtitle' => __('messages.why_choose_subtitle'),
])

<div class="container py-5">
    @include('components.breadcrumbs', [
        'items' => [
            ['name' => __('messages.home'), 'url' => localized_url('/')],
            ['name' => __('messages.why_choose_title'), 'url' => localized_url('why-choose-us')],
        ],
    ])
    <div class="row g-4">
        @foreach(range(1, 4) as $i)
            <div class="col-md-6">
                <div class="p-4 h-100 rounded-4 border bg-white shadow-sm">
                    <h2 class="h5" style="color:#1a585e;">{{ __('messages.why_choose_point_'.$i.'_title') }}</h2>
                    <p class="text-muted mb-0">{{ __('messages.why_choose_point_'.$i.'_body') }}</p>
                </div>
            </div>
        @endforeach
    </div>
    @if(function_exists('public_locale') && in_array(public_locale(), ['en', 'us'], true))
        <section class="mt-5" aria-labelledby="why-choose-how-heading">
            <h2 id="why-choose-how-heading" class="h4 mb-3" style="color:#1a585e;">How ordering and pricing work</h2>
            <p class="text-muted">Browse the <a href="{{ localized_url('marketplace') }}">marketplace</a>, fund a EUR wallet, and check out per listing. The publisher is paid after you approve the live URL, or automatically 72 hours after they submit it. See <a href="{{ localized_url('how-it-works') }}">how it works</a> and the <a href="{{ localized_url('refund-policy') }}">refund policy</a> for the 30-day removal report path.</p>
            <p class="text-muted mb-0">Public prices are listing checkout amounts in euro. Country medians: <a href="{{ url('/guest-post-prices-europe') }}">EU guest-post price index</a>. Managed packages sit on <a href="{{ localized_url('pricing') }}">Pricing</a>. We do not publish order volume or advertiser counts.</p>
        </section>
        <p class="small mt-4 mb-0">
            <a href="{{ url('/for-agencies') }}">For agencies</a>
            · <a href="{{ url('/sponsored-articles') }}">Sponsored articles</a>
            · <a href="{{ url('/alternatives') }}">How we differ</a>
            · <a href="{{ localized_url('about') }}">About</a>
            · <a href="{{ url('/guest-posts-germany') }}">Germany</a>
            · <a href="{{ url('/guest-posts-france') }}">France</a>
            · <a href="{{ url('/guest-posts-netherlands') }}">Netherlands</a>
            · <a href="{{ url('/guest-posts-poland') }}">Poland</a>
        </p>
    @endif
</div>
@endsection
