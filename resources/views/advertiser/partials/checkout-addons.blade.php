@php
    $addons = $checkoutAddOns ?? app(\App\Services\Marketplace\CheckoutAddOnService::class);
    $guaranteePercent = $addons->guaranteePercent();
    $guaranteeTerms = $addons->termsText();
    $anyGuarantee = $addons->anyLineQualifiesForGuarantee($cartItems);
@endphp

<div class="card border-0 shadow-sm mb-4" id="checkoutAddOns">
    <div class="card-header bg-white fw-semibold">
        Article source &amp; link guarantee
    </div>
    <div class="card-body">
        @foreach($cartItems as $item)
            @if(empty($item['paying_now']) && empty($item['stays_in_cart']))
                @continue
            @endif
            @php
                $isLinkInsertion = \App\Support\LinkInsertionProduct::isLinkInsertion($item);
                $publisherOffered = !empty($item['writing_offered']);
                $fee = (float) ($item['writing_fee'] ?? 0);
                $writer = $item['writing_writer'] ?? ($publisherOffered ? 'publisher' : 'slb');
                $guaranteeOffered = !empty($item['guarantee_offered']);
                $payingNow = !empty($item['paying_now']);
                $writtenSelected = \App\Services\Marketplace\CheckoutAddOnService::isWrittenSource($item);
                $writingTarget = (string) ($item['writing_target_url'] ?? '');
                $writingAnchor = (string) ($item['writing_anchor_text'] ?? '');
            @endphp
            <div class="border rounded p-3 mb-3 checkout-writing-line"
                 data-site-id="{{ $item['id'] }}"
                 data-writing-offered="{{ $publisherOffered ? '1' : '0' }}"
                 data-writing-fee="{{ $fee }}"
                 data-base-total="{{ (float) ($item['total'] ?? $item['price'] ?? 0) }}"
                 data-paying-now="{{ $payingNow ? '1' : '0' }}"
                 data-guarantee-offered="{{ $guaranteeOffered ? '1' : '0' }}">
                <div class="fw-semibold mb-2">{{ $item['name'] }}
                    @if($isLinkInsertion)
                        <span class="badge text-bg-light border ms-1">Link insertion</span>
                    @endif
                </div>
                @if($isLinkInsertion)
                    <p class="small text-muted mb-2">{{ \App\Support\LinkInsertionProduct::PERMANENT_COPY }}</p>
                    <div class="mb-2">
                        <label class="form-label small" for="preferredArticle{{ $item['id'] }}">Preferred article URL</label>
                        <input type="url" class="form-control form-control-sm" required
                               name="preferred_article_url[{{ $item['id'] }}]"
                               id="preferredArticle{{ $item['id'] }}"
                               placeholder="https://"
                               value="{{ $item['preferred_article_url'] ?? '' }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="insertionTarget{{ $item['id'] }}">Your target URL</label>
                        <input type="url" class="form-control form-control-sm" required
                               name="insertion_target_url[{{ $item['id'] }}]"
                               id="insertionTarget{{ $item['id'] }}"
                               placeholder="https://"
                               value="{{ $writingTarget }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small" for="insertionAnchor{{ $item['id'] }}">Anchor text</label>
                        <input type="text" class="form-control form-control-sm" required
                               name="insertion_anchor_text[{{ $item['id'] }}]"
                               id="insertionAnchor{{ $item['id'] }}"
                               maxlength="160"
                               value="{{ $writingAnchor }}">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1"
                               name="insertion_sentence_same[{{ $item['id'] }}]"
                               id="insertionSame{{ $item['id'] }}"
                               @checked(!empty($item['insertion_sentence_same']))>
                        <label class="form-check-label" for="insertionSame{{ $item['id'] }}">Insert in the same sentence</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1"
                               name="insertion_sentence_new[{{ $item['id'] }}]"
                               id="insertionNew{{ $item['id'] }}"
                               @checked(!empty($item['insertion_sentence_new']))>
                        <label class="form-check-label" for="insertionNew{{ $item['id'] }}">Add a new sentence</label>
                    </div>
                    <div class="mb-2 mt-1">
                        <label class="form-label small" for="insertionNewText{{ $item['id'] }}">New sentence (if chosen)</label>
                        <textarea class="form-control form-control-sm" rows="2"
                                  name="insertion_sentence_new_text[{{ $item['id'] }}]"
                                  id="insertionNewText{{ $item['id'] }}"
                                  maxlength="500">{{ $item['insertion_sentence_new_text'] ?? '' }}</textarea>
                    </div>
                    @if($guaranteeOffered)
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" value="1"
                                   name="link_guarantee_items[{{ $item['id'] }}]"
                                   id="linkGuaranteeItem{{ $item['id'] }}"
                                   @checked(!empty($item['link_guarantee']))>
                            <label class="form-check-label" for="linkGuaranteeItem{{ $item['id'] }}">
                                Add {{ \App\Support\LinkInsertionProduct::GUARANTEE_LABEL }}
                                @if(!empty($item['guarantee_fee_label']))
                                    <span class="text-muted">(+ {{ $item['guarantee_fee_label'] }} — {{ rtrim(rtrim(number_format($guaranteePercent, 2), '0'), '.') }}% of this item)</span>
                                @endif
                            </label>
                        </div>
                        <p class="small text-muted mb-0 mt-1">{{ \App\Support\LinkInsertionProduct::withAddonPolicyCopy() }} {{ \App\Support\LinkInsertionProduct::withoutAddonRefundCopy() }}</p>
                    @endif
                    @continue
                @endif
                @if(!$payingNow)
                    <p class="small text-muted mb-2">Choose writing to pay this site now without an uploaded article.</p>
                @endif
                <div class="form-check">
                    <input class="form-check-input checkout-content-source" type="radio"
                           name="content_source[{{ $item['id'] }}]" value="buyer"
                           id="contentSourceBuyer{{ $item['id'] }}" @checked(!$writtenSelected)>
                    <label class="form-check-label" for="contentSourceBuyer{{ $item['id'] }}">
                        I supply the article
                        <span class="text-muted">(listing price)</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input checkout-content-source" type="radio"
                           name="content_source[{{ $item['id'] }}]" value="written"
                           id="contentSourceWritten{{ $item['id'] }}" @checked($writtenSelected)>
                    <label class="form-check-label" for="contentSourceWritten{{ $item['id'] }}">
                        @if($publisherOffered)
                            Publisher writes the article
                            @if($fee > 0)
                                <span class="text-muted">(+ {{ format_money_pay($fee) }} writing fee)</span>
                            @endif
                        @else
                            SEOLinkBuildings writes the article
                            @if($fee > 0)
                                <span class="text-muted">(+ {{ format_money_pay($fee) }} flat writing fee, paid to SEOLinkBuildings — not the publisher)</span>
                            @endif
                        @endif
                    </label>
                </div>
                <div class="checkout-writing-brief {{ $writtenSelected ? '' : 'd-none' }} mt-2">
                    <label class="form-label small" for="writingTarget{{ $item['id'] }}">Target URL for the written article</label>
                    <input type="url" class="form-control form-control-sm checkout-writing-target"
                           name="writing_brief[{{ $item['id'] }}][target_url]"
                           id="writingTarget{{ $item['id'] }}"
                           placeholder="https://"
                           value="{{ $writingTarget }}">
                    <label class="form-label small mt-2" for="writingAnchor{{ $item['id'] }}">Preferred anchor text (optional)</label>
                    <input type="text" class="form-control form-control-sm"
                           name="writing_brief[{{ $item['id'] }}][anchor_text]"
                           id="writingAnchor{{ $item['id'] }}"
                           maxlength="160"
                           value="{{ $writingAnchor }}">
                </div>
                @if(!$guaranteeOffered)
                    <p class="small text-muted mb-0 mt-2">12-month link guarantee is not available — this listing’s publication period is shorter than 12 months.</p>
                @endif
            </div>
        @endforeach

        @php
            $hasGuestPostGuarantee = collect($cartItems)->contains(function ($row) use ($addons) {
                return empty($row['stays_in_cart']) && !empty($row['guarantee_offered']) && ! \App\Support\LinkInsertionProduct::isLinkInsertion($row);
            });
            $hasLinkInsertion = collect($cartItems)->contains(function ($row) {
                return empty($row['stays_in_cart']) && \App\Support\LinkInsertionProduct::isLinkInsertion($row);
            });
        @endphp
        @if($hasGuestPostGuarantee)
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" value="1" id="linkGuarantee" name="link_guarantee">
                <label class="form-check-label" for="linkGuarantee">
                    Add a 12-month link guarantee
                    <span class="text-muted">({{ rtrim(rtrim(number_format($guaranteePercent, 2), '0'), '.') }}% of eligible guest-post placements — 12 months or permanent only)</span>
                </label>
            </div>
            <p class="small text-muted mb-0 mt-2" id="linkGuaranteeTerms">{{ $guaranteeTerms }}</p>
        @elseif(!$hasLinkInsertion)
            <p class="small text-muted mb-0 mt-2" id="linkGuaranteeTerms">{{ $addons->eligibilityHelpText() }}</p>
        @endif
        @if($hasLinkInsertion)
            <p class="small text-muted mb-0 mt-2">{{ $addons->linkInsertionTermsText() }}</p>
        @endif
    </div>
</div>
