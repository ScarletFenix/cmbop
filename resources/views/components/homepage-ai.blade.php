@php
    $section = $section ?? 'all';
    $facts = class_exists(\App\Support\HomepageAiVisibility::class)
        ? \App\Support\HomepageAiVisibility::facts()
        : [];
    $faqs = class_exists(\App\Support\HomepageAiVisibility::class)
        ? \App\Support\HomepageAiVisibility::faqItems()
        : [];
    $landers = class_exists(\App\Support\CountryLander::class)
        ? \App\Support\CountryLander::siblings()
        : [];
    $trustLinks = class_exists(\App\Support\HomepageAiVisibility::class)
        ? \App\Support\HomepageAiVisibility::trustLinks()
        : [];
@endphp

@if($facts !== [] && in_array($section, ['all', 'facts'], true))
<section class="slb-section slb-home-facts" id="marketplace-facts" aria-labelledby="home-facts-heading">
    <div class="container" style="max-width:1100px;">
        <div class="text-center mb-4 slb-reveal">
            <div class="slb-section-kicker">Facts</div>
            <h2 id="home-facts-heading" class="slb-section-title">SEOLinkBuildings at a glance</h2>
            <p class="slb-section-lead mb-0">Extractable company and marketplace facts. No review scores, client logos, or order-volume claims.</p>
        </div>
        <dl class="slb-home-facts__grid">
            @foreach($facts as $fact)
                <div class="slb-home-facts__item">
                    <dt>{{ $fact['label'] }}</dt>
                    <dd>{{ $fact['value'] }}</dd>
                </div>
            @endforeach
        </dl>
        @if(view()->exists('components.country-lander-nav') && $landers !== [])
            @include('components.country-lander-nav', [
                'title' => 'Guest posts by European market',
                'landers' => $landers,
                'label' => 'Country guest-post landers',
            ])
        @endif
        @if($trustLinks !== [])
            <p class="slb-home-facts__links">
                @foreach($trustLinks as $link)
                    @if(!$loop->first) · @endif
                    <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                @endforeach
            </p>
        @endif
    </div>
</section>
@endif

@if($faqs !== [] && in_array($section, ['all', 'faq'], true))
<section class="slb-section slb-home-faq bg-white" id="homepage-faq" aria-labelledby="home-faq-heading">
    <div class="container" style="max-width:800px;">
        <div class="text-center mb-4 slb-reveal">
            <div class="slb-section-kicker">{{ __('messages.nav_faq') }}</div>
            <h2 id="home-faq-heading" class="slb-section-title">Questions buyers and publishers ask</h2>
            <p class="slb-section-lead mb-0">Plain answers for AI assistants and people comparing European guest-post marketplaces.</p>
        </div>
        @foreach($faqs as $faq)
            <article class="slb-home-faq__item">
                <h3 class="slb-home-faq__q">{{ $faq['q'] }}</h3>
                <p class="slb-home-faq__a">{{ $faq['a'] }}</p>
            </article>
        @endforeach
        <p class="text-center small mb-0">
            <a href="{{ function_exists('localized_url') ? localized_url('faq') : url('/faq') }}">Full FAQ</a>
            · <a href="{{ url('/is-seolinkbuildings-safe') }}">Is SEOLinkBuildings safe?</a>
        </p>
    </div>
</section>
@endif

@once
<style>
.slb-home-facts {
  background:
    radial-gradient(700px 280px at 90% 0%, rgba(63, 174, 178, 0.10), transparent 70%),
    #fff;
}
.slb-home-facts__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem 1.25rem;
  margin: 0;
}
@media (min-width: 768px) {
  .slb-home-facts__grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
.slb-home-facts__item {
  margin: 0;
  padding: 1rem 1.1rem;
  border: 1px solid rgba(26, 88, 94, 0.12);
  border-radius: 14px;
  background: #f7fafb;
}
.slb-home-facts__item dt {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #1a585e;
  margin-bottom: 0.35rem;
}
.slb-home-facts__item dd {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #1e293b;
  line-height: 1.35;
}
.slb-home-facts__links {
  margin: 1.35rem 0 0;
  text-align: center;
  font-size: 0.9rem;
}
.slb-home-facts__links a {
  color: #1a585e;
  font-weight: 600;
  text-decoration: none;
}
.slb-home-facts__links a:hover,
.slb-home-facts__links a:focus-visible {
  text-decoration: underline;
}
.slb-home-faq__item {
  margin-bottom: 1.35rem;
}
.slb-home-faq__q {
  margin: 0 0 0.4rem;
  font-size: 1.05rem;
  font-weight: 700;
  color: #1a585e;
  line-height: 1.35;
}
.slb-home-faq__a {
  margin: 0;
  color: #4b5563;
  line-height: 1.6;
}
</style>
@endonce
