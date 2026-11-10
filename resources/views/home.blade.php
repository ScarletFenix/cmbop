@extends('layouts.app')

@section('title', __('messages.meta_home_title'))
@section('description', welcome_bonus_message('meta_home_description', 'meta_home_description_off'))
@section('canonical', localized_url('/'))

@push('head')
@php
    $heroMarkWebp = asset('assets/img/logo1-hero.webp').'?v='.(@filemtime(public_path('assets/img/logo1-hero.webp')) ?: '1');
@endphp
<link rel="preload" as="image" href="{{ $heroMarkWebp }}" type="image/webp" fetchpriority="high">
<style>
  /* Homepage first paint: same wash as the hero so refresh is not a white splash. */
  html, body { background: #f7fafb; }
  .slb-reveal { animation: none !important; opacity: 1 !important; }
</style>
@if(class_exists(\App\Support\HomepageAiVisibility::class) && method_exists(\App\Support\HomepageAiVisibility::class, 'jsonLdBlocks'))
    @foreach(\App\Support\HomepageAiVisibility::jsonLdBlocks() as $homeJsonLd)
<script type="application/ld+json">
{!! \App\Support\BrandOrganization::jsonLd($homeJsonLd) !!}
</script>
    @endforeach
@else
<script type="application/ld+json">
{!! json_encode([
    '@@context' => 'https://schema.org',
    '@type' => 'WebSite',
    '@id' => \App\Support\BrandOrganization::websiteId(),
    'name' => 'SEOLinkBuildings',
    'url' => localized_url('/'),
    'inLanguage' => (class_exists(\App\Support\PublicI18n::class) && method_exists(\App\Support\PublicI18n::class, 'htmlLang'))
        ? \App\Support\PublicI18n::htmlLang()
        : 'en-GB',
    'publisher' => [
        '@id' => \App\Support\BrandOrganization::organizationId(),
    ],
    'hasPart' => [
        [
            '@type' => 'AboutPage',
            'name' => __('messages.about_title'),
            'url' => localized_url('about'),
        ],
    ],
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}
</script>
@endif
@endpush

@section('content')
    @if (view()->exists('components.hero'))
        @include('components.hero')
    @endif
    @if (function_exists('public_locale') && public_locale() === 'it' && class_exists(\App\Support\ItalianMoneyLanders::class) && method_exists(\App\Support\ItalianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\ItalianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Pagine per chi cerca guest post e backlink in Italia',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'de' && class_exists(\App\Support\GermanMoneyLanders::class) && method_exists(\App\Support\GermanMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\GermanMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'at' && class_exists(\App\Support\AustrianMoneyLanders::class) && method_exists(\App\Support\AustrianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\AustrianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding in Österreich',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'ch' && class_exists(\App\Support\SwissMoneyLanders::class) && method_exists(\App\Support\SwissMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SwissMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Seiten für Gastbeiträge, Backlinks und Linkbuilding in der Schweiz',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'es' && class_exists(\App\Support\SpanishMoneyLanders::class) && method_exists(\App\Support\SpanishMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\SpanishMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Páginas de guest posts, backlinks y link building en España',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'pt' && class_exists(\App\Support\PortugueseMoneyLanders::class) && method_exists(\App\Support\PortugueseMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\PortugueseMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Páginas de guest posts, backlinks e link building em Portugal',
            ])
        </div>
    @endif
    @if (function_exists('public_locale') && public_locale() === 'ro' && class_exists(\App\Support\RomanianMoneyLanders::class) && method_exists(\App\Support\RomanianMoneyLanders::class, 'clusterLinks') && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\RomanianMoneyLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Pagini de guest post, backlinkuri și link building în România',
            ])
        </div>
    @endif
    @php
        $nordicChrome = class_exists(\App\Support\MoneyLanderCatalog::class)
            ? \App\Support\MoneyLanderCatalog::chrome(function_exists('public_locale') ? (string) public_locale() : '')
            : null;
    @endphp
    @if($nordicChrome && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => $nordicChrome['home_links'],
                'current' => 'home',
                'title' => $nordicChrome['cluster_title'] ?? '',
            ])
        </div>
    @endif
    @if(function_exists('public_locale') && in_array(public_locale(), ['en', 'us'], true) && class_exists(\App\Support\EnglishCommercialLanders::class) && view()->exists('components.italian-seo-cluster-nav'))
        <div class="container py-4">
            @include('components.italian-seo-cluster-nav', [
                'links' => \App\Support\EnglishCommercialLanders::clusterLinks('home'),
                'current' => 'home',
                'title' => 'Agencies, sponsored articles, and European markets',
            ])
        </div>
    @endif
    @if(class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'isEnglishHome')
        && \App\Support\HomepageAiVisibility::isEnglishHome()
        && view()->exists('components.homepage-ai'))
        @include('components.homepage-ai', ['section' => 'facts'])
    @endif
    @include('components.features')
    @include('components.how-it-works')
    @if(class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'isEnglishHome')
        && \App\Support\HomepageAiVisibility::isEnglishHome()
        && view()->exists('components.homepage-how-company'))
        @include('components.homepage-how-company')
    @endif
    @if(class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'isEnglishHome')
        && \App\Support\HomepageAiVisibility::isEnglishHome()
        && view()->exists('components.homepage-ai'))
        @include('components.homepage-ai', ['section' => 'faq'])
    @endif
    @include('components.pricing')
    @include('components.newsletter')
    @include('components.cta')
@endsection
