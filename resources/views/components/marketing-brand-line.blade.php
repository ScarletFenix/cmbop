@php
    $homeHref = function_exists('localized_url') ? localized_url('/') : url('/');
@endphp
<p class="marketing-brand-line">
    <a href="{{ $homeHref }}" class="marketing-brand-link">SEOLinkBuildings</a>
</p>

<style>
  .marketing-brand-line {
    margin: 0 0 0.65rem;
  }
  .marketing-brand-link {
    display: inline-block;
    font-size: clamp(1.35rem, 2.4vw, 1.85rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    color: #1a585e;
    text-decoration: none;
    line-height: 1.15;
    white-space: nowrap;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .marketing-brand-link:hover {
    color: #3faeb2;
  }
</style>
