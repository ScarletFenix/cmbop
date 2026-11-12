{{--
    The two facts every listing repeats: link attribute, and typical turnaround.

    @param \App\Models\Site|null $site  Preferred — uses Site label helpers.
    @param string|null $linkType        Fallback when $site is not passed.
    @param string|null $turnaround      Fallback raw turnaround code.
    @param bool $isLinkInsertionCatalog When true, chips are insertion-specific.
--}}
@php
    $chipLinkType = null;
    $chipTurnaround = '';
    $chipLanguageLabels = [];
    $chipMinAge = null;
    $isLinkInsertionCatalog = $isLinkInsertionCatalog ?? false;
    try {
        if ($isLinkInsertionCatalog && $site) {
            $chipLinkType = $site->linkInsertionLinkTypeLabel();
            $chipTurnaround = (string) ($site->linkInsertionDeliveryLabel() ?? '');
            $chipMinAge = $site->linkInsertionMinArticleAgeLabel();
        } else {
            $chipLinkType = $site?->linkTypeLabel()
                ?: (match (strtolower(trim((string) ($linkType ?? '')))) {
                    'dofollow' => 'DoFollow',
                    'nofollow' => 'NoFollow',
                    default => null,
                });
            $chipTurnaround = $site?->turnaroundLabel()
                ?: trim((string) ($turnaround ?? ''));
        }
        $knownLanguages = marketplace_languages();
        foreach (array_slice($site?->languageCodes() ?? [], 0, 2) as $code) {
            $code = strtolower(trim((string) $code));
            // Leftover Hostinger junk ("not-json", "??") must not paint a chip.
            if (! isset($knownLanguages[$code])) {
                continue;
            }
            $chipLanguageLabels[] = $knownLanguages[$code];
        }
    } catch (\Throwable $e) {
        report($e);
    }
@endphp

@if($chipLanguageLabels !== [] || $chipLinkType || $chipTurnaround !== '' || $chipMinAge || $isLinkInsertionCatalog)
<div class="catalog-meta-chips">
    @foreach($chipLanguageLabels as $chipLanguage)
    <span class="catalog-meta-chip catalog-meta-chip--language">
        <i class="fa-solid fa-language" aria-hidden="true"></i>
        <span>{{ $chipLanguage }}</span>
    </span>
    @endforeach
    @if($chipLinkType)
    <span class="catalog-meta-chip">
        <i class="fa-solid fa-link" aria-hidden="true"></i>
        <span>{{ $chipLinkType }}</span>
    </span>
    @endif
    @if($chipTurnaround !== '')
        <span class="catalog-meta-chip">
            <i class="fa-regular fa-clock" aria-hidden="true"></i>
            <span>{{ $chipTurnaround }}</span>
        </span>
    @endif
    @if($chipMinAge)
        <span class="catalog-meta-chip">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            <span>{{ $chipMinAge }}</span>
        </span>
    @endif
    @if($isLinkInsertionCatalog)
        <span class="catalog-meta-chip catalog-meta-chip--guarantee">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <span>{{ __('messages.catalog_li_guarantee_badge') }}</span>
        </span>
    @endif
</div>
@endif
