@php
    $legal = class_exists(\App\Support\BrandOrganization::class)
        ? (\App\Support\BrandOrganization::confirmedLegalName() ?: 'Teqno Ltd')
        : 'Teqno Ltd';
    $registration = (string) config('billing.company.registration_no', config('company.registration_no', '17392540'));
    $founder = class_exists(\App\Support\BrandPerson::class) ? \App\Support\BrandPerson::publicName() : 'M. Arslan';
    $countries = class_exists(\App\Support\HomepageAiVisibility::class)
        && method_exists(\App\Support\HomepageAiVisibility::class, 'countryCount')
        ? \App\Support\HomepageAiVisibility::countryCount()
        : 0;
    $aboutUrl = function_exists('localized_url') ? localized_url('about') : url('/about');
    $publisherUrl = function_exists('localized_url') ? localized_url('become-a-publisher') : url('/become-a-publisher');
    $howUrl = function_exists('localized_url') ? localized_url('how-it-works') : url('/how-it-works');
    $indexUrl = url('/guest-post-prices-europe');
@endphp

<section class="slb-section slb-home-how-company" id="homepage-how-company" aria-labelledby="home-how-company-heading">
    <div class="container" style="max-width:1100px;">
        <div class="text-center mb-4 slb-reveal">
            <div class="slb-section-kicker">Ordering &amp; company</div>
            <h2 id="home-how-company-heading" class="slb-section-title">How it works &amp; company</h2>
        </div>
        <div class="slb-home-how-company__grid">
            <article class="slb-home-how-company__card">
                <p>
                    After you buy, payment is held until you approve the live URL, or for 72 hours after the publisher submits it —
                    see <a href="{{ $howUrl }}">how ordering works</a>.
                    @if($countries > 0)
                        Dedicated pages cover {{ $countries }} European markets, with live from-prices on the
                        <a href="{{ $indexUrl }}">EU guest-post price index</a>.
                    @else
                        Country from-prices are on the <a href="{{ $indexUrl }}">EU guest-post price index</a>.
                    @endif
                    <a href="{{ $publisherUrl }}">Publishers pay nothing</a> to list a site.
                </p>
            </article>
            <article class="slb-home-how-company__card">
                <p>
                    The marketplace is operated by {{ $legal }} (company number {{ $registration }}), founded in 2024 by {{ $founder }},
                    at 20 Wenlock Road, London N1 7GU.
                    Company details are on the <a href="{{ $aboutUrl }}">About</a> page and in <a href="{{ url('/llms.txt') }}">llms.txt</a>.
                </p>
            </article>
        </div>
    </div>
</section>

@once
<style>
.slb-home-how-company {
  background:
    radial-gradient(700px 280px at 8% 0%, rgba(63, 174, 178, 0.10), transparent 70%),
    #f7fafb;
}
.slb-home-how-company__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
}
@media (min-width: 768px) {
  .slb-home-how-company__grid {
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
  }
}
.slb-home-how-company__card {
  margin: 0;
  padding: 1.25rem 1.35rem;
  border: 1px solid rgba(26, 88, 94, 0.12);
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 8px 24px rgba(26, 88, 94, 0.04);
}
.slb-home-how-company__card p {
  margin: 0;
  color: #4b5563;
  line-height: 1.65;
  font-size: 0.98rem;
}
.slb-home-how-company__card a {
  color: #1a585e;
  font-weight: 600;
  text-decoration: none;
}
.slb-home-how-company__card a:hover,
.slb-home-how-company__card a:focus-visible {
  text-decoration: underline;
}
</style>
@endonce
