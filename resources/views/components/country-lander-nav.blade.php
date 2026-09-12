@props([
    'title' => 'Guest posts by market',
    'subtitle' => null,
    'landers' => [],
    'label' => 'Country landers',
])

@if(!empty($landers))
<nav {{ $attributes->class('country-lander-nav') }} aria-label="{{ $label }}">
    <div class="country-lander-nav__intro">
        <h2 class="country-lander-nav__title">{{ $title }}</h2>
        @if($subtitle)
            <p class="country-lander-nav__subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    <ul class="country-lander-nav__grid">
        @foreach($landers as $landerLink)
            <li>
                <a class="country-lander-nav__card" href="{{ $landerLink['url'] }}">
                    <span class="country-lander-nav__market">{{ $landerLink['market'] }}</span>
                    @if(!empty($landerLink['kicker']))
                        <span class="country-lander-nav__kicker">{{ $landerLink['kicker'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<style>
  .country-lander-nav {
    margin-top: 3rem;
    padding: 2rem 1.25rem 1.75rem;
    border: 1px solid rgba(26, 88, 94, 0.12);
    border-radius: 1.25rem;
    background: linear-gradient(180deg, #f4fbfb 0%, #ffffff 100%);
  }
  .country-lander-nav__intro {
    text-align: center;
    margin-bottom: 1.25rem;
  }
  .country-lander-nav__title {
    margin: 0 0 0.35rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a585e;
    letter-spacing: -0.02em;
  }
  .country-lander-nav__subtitle {
    margin: 0 auto;
    max-width: 36rem;
    color: #6b7280;
    font-size: 0.95rem;
  }
  .country-lander-nav__grid {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
  }
  @media (min-width: 768px) {
    .country-lander-nav__grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }
  @media (min-width: 1200px) {
    .country-lander-nav__grid {
      grid-template-columns: repeat(6, minmax(0, 1fr));
    }
  }
  .country-lander-nav__card {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 5.5rem;
    height: 100%;
    padding: 0.9rem 0.85rem;
    border: 1px solid rgba(26, 88, 94, 0.14);
    border-radius: 0.9rem;
    background: #fff;
    text-decoration: none;
    color: #1a585e;
    box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
  }
  .country-lander-nav__card:hover,
  .country-lander-nav__card:focus-visible {
    color: #1a585e;
    text-decoration: none;
    border-color: #1a585e;
    box-shadow: 0 8px 20px rgba(26, 88, 94, 0.10);
    transform: translateY(-1px);
  }
  .country-lander-nav__market {
    font-weight: 700;
    font-size: 0.95rem;
    line-height: 1.3;
  }
  .country-lander-nav__kicker {
    margin-top: 0.25rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: #5b6b73;
    line-height: 1.3;
  }
</style>
@endif
