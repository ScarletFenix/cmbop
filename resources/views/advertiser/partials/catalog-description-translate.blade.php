{{-- v1: outbound Google Translate. One stored description; original HTML stays on the page. --}}
@php
    $translateShowsIdentity = $showsIdentity ?? true;
    $translateHtml = $site->description ?? null;
    $translateLang = $site->language ?? null;
    $offerTranslate = $translateShowsIdentity
        && \App\Support\SiteDescriptionRules::shouldOfferEnglishTranslate($translateLang, $translateHtml);
    $translateUrl = $offerTranslate
        ? catalog_description_translate_url($translateHtml)
        : null;
@endphp
@if($offerTranslate && $translateUrl)
    <p class="catalog-desc-translate small mb-0 mt-2">
        <span class="catalog-desc-translate__lang">Brief in {{ fullLanguage($translateLang) }}</span>
        <span aria-hidden="true"> · </span>
        <a href="{{ $translateUrl }}"
           target="_blank"
           rel="noopener noreferrer"
           class="catalog-desc-translate__link">Translate to English</a>
    </p>
    <p class="catalog-desc-translate__note small text-muted mb-0">English (machine translation)</p>
@endif
