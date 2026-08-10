@extends('publisher.layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/publisher-websites.css') }}?v={{ @filemtime(public_path('assets/css/publisher-websites.css')) ?: '1' }}">

<div class="container-fluid">
    <h3 class="mb-4"><span id="formHeader">Add New Website</span></h3>

    <!-- Flash Messages -->

    @if(($errors ?? null)?->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <button id="showFormBtn" class="btn btn-primary mb-3 shadow-sm">
        <i class="fa fa-plus"></i> Add New Website
    </button>

    <button id="showBulkRequestBtn" type="button" class="btn mb-3 shadow-sm btn-outline-secondary ms-1"
            data-bs-toggle="modal" data-bs-target="#bulkRequestModal"
            @if(!empty($openBulkRequest)) disabled title="You already have an open bulk request" @endif>
        <i class="fa fa-layer-group"></i> I want to add many sites
    </button>

    @if(!empty($awaitingDetailsCount) && $awaitingDetailsCount > 0)
        <a href="{{ route('publisher.bulk-sites.complete') }}" class="btn mb-3 shadow-sm btn-upload ms-1">
            <i class="fa fa-pen-to-square"></i> Complete details ({{ $awaitingDetailsCount }})
        </a>
    @endif
    @if(!empty($detailsCompleteCount) && $detailsCompleteCount > 0)
        <a href="{{ route('publisher.bulk-sites.review') }}" class="btn mb-3 shadow-sm btn-outline-primary ms-1">
            <i class="fa fa-clipboard-check"></i> Review &amp; submit ({{ $detailsCompleteCount }})
        </a>
    @endif

    @if(!empty($openBulkRequest))
        <div class="alert alert-light border mb-3">
            <strong>Bulk request #{{ $openBulkRequest->id }}</strong>
            — status: <span class="text-capitalize">{{ str_replace('_', ' ', $openBulkRequest->status) }}</span>.
            You submitted <strong>URL + price</strong> only — track progress under
            <a href="{{ route('publisher.websites', ['status' => 'pending']) }}" class="fw-semibold">Pending</a>.
            Next: our marketer adds DA/DR/traffic/language/country/niches → you add descriptions &amp; listing details → we approve.
            @if(($openBulkRequest->estimated_count ?? 0) > 0)
                <span class="d-block small text-muted mt-1">{{ $openBulkRequest->estimated_count }} site(s) in this request.</span>
            @endif
        </div>
    @endif


    {{-- Guided bulk: publisher submits URL + price only --}}
    <div class="modal fade" id="bulkRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('publisher.bulk-sites.request') }}" class="modal-content" id="bulkRequestForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add many websites</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="border rounded-3 p-3 mb-3" style="background:#f7fafb;">
                        <div class="fw-semibold mb-2">How bulk onboarding works</div>
                        <ol class="small text-muted mb-0 ps-3">
                            <li class="mb-1"><strong>You</strong> add only <strong>Website URL</strong> + <strong>Price</strong> (type, paste, or upload a 2-column sheet).</li>
                            <li class="mb-1"><strong>Our marketer</strong> adds stats and niches (DA, DR, traffic, language, country, niches).</li>
                            <li class="mb-1"><strong>You</strong> finish descriptions, link type, and timing, then review &amp; submit.</li>
                            <li><strong>We</strong> review and approve — sites stay hidden until then.</li>
                        </ol>
                    </div>

                    @error('sites')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror

                    <div class="mb-3 border rounded-3 p-3">
                        <div class="fw-semibold mb-2">Import URL + price</div>
                        <p class="small text-muted mb-3 mb-md-2">
                            Upload a CSV/TSV with <strong>two columns</strong> (Website URL, Price), or paste the same from Excel/Sheets.
                            Header row optional. Excel: <em>File → Save As → CSV</em>, or copy both columns and paste below.
                        </p>

                        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <label class="btn btn-sm btn-outline-primary mb-0" for="bulkSheetFile">
                                <i class="fa fa-file-csv me-1"></i> Upload sheet (CSV / TSV)
                            </label>
                            <input type="file" id="bulkSheetFile" class="d-none"
                                   accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain">
                            <a href="#" id="bulkSheetTemplateBtn" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download me-1"></i> Sample CSV
                            </a>
                            <span class="form-text mb-0" id="bulkSheetFileName"></span>
                        </div>

                        <label class="form-label mb-1" for="bulkPasteUrls">Paste into the box, then click Fill rows</label>
                        <textarea id="bulkPasteUrls" class="form-control form-control-sm font-monospace" rows="5"
                                  placeholder="https://site-one.com,99&#10;https://site-two.com,150&#10;&#10;# Excel: copy two columns (URL + Price) and paste here&#10;# URLs only (one per line) also work — add prices in the table"></textarea>
                        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                            <button type="button" class="btn btn-sm btn-primary" id="bulkPasteUrlsBtn">
                                <i class="fa fa-clipboard-list me-1"></i> Fill rows from paste
                            </button>
                            <span class="form-text mb-0">Formats: <code>url,price</code> · tab from Excel · <code>url price</code> · URLs only</span>
                        </div>
                        <div class="small text-success mt-1 d-none" id="bulkPasteUrlsSuccess" role="status"></div>
                        <div class="small text-danger mt-1 d-none" id="bulkPasteUrlsError" role="alert"></div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Your sites (URL + price only)</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bulkAddRowBtn">
                            <i class="fa fa-plus"></i> Add row
                        </button>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0" id="bulkUrlPriceTable">
                            <thead>
                                <tr>
                                    <th style="min-width:14rem;">Website URL</th>
                                    <th style="width:8.5rem;">Price (€)</th>
                                    <th style="width:2.5rem;"></th>
                                </tr>
                            </thead>
                            <tbody id="bulkUrlPriceBody">
                                @php
                                    $oldSites = old('sites');
                                    if (!is_array($oldSites) || count($oldSites) < 2) {
                                        $oldSites = [['url' => '', 'price' => ''], ['url' => '', 'price' => '']];
                                    }
                                @endphp
                                @foreach($oldSites as $i => $row)
                                    <tr class="bulk-url-price-row">
                                        <td>
                                            <input type="url" name="sites[{{ $i }}][url]"
                                                   class="form-control form-control-sm @error('sites.'.$i.'.url') is-invalid @enderror"
                                                   placeholder="https://example.com"
                                                   value="{{ $row['url'] ?? '' }}" required>
                                            @error('sites.'.$i.'.url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td>
                                            <input type="number" name="sites[{{ $i }}][price]" step="0.01" min="0"
                                                   class="form-control form-control-sm @error('sites.'.$i.'.price') is-invalid @enderror"
                                                   placeholder="99"
                                                   value="{{ $row['price'] ?? '' }}" required>
                                            @error('sites.'.$i.'.price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">&times;</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="form-text mb-3">Minimum 2 sites. One open bulk request at a time. For a single site, use <strong>Add New Website</strong>.</div>

                    <div class="mb-0">
                        <label class="form-label">Note for our team (optional)</label>
                        <textarea name="publisher_note" class="form-control @error('publisher_note') is-invalid @enderror"
                                  rows="2" maxlength="2000" placeholder="Niches, languages, or anything we should know…">{{ old_text('publisher_note') }}</textarea>
                        @error('publisher_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit URL + prices</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 d-none" id="formCard">
        <div class="card-body">
            <form id="addSiteForm" class="needs-validation" novalidate method="POST" action="{{ route('publisher.sites.store') }}">
                @csrf
                <input type="hidden" name="_method" id="methodField" value="POST">

                <div class="site-wizard-steps" id="siteWizardSteps" aria-label="Add website steps">
                    <div class="site-wizard-step active" data-step="1">
                        <span class="wiz-num">1</span>
                        <span>Site basics</span>
                    </div>
                    <div class="site-wizard-step" data-step="2">
                        <span class="wiz-num">2</span>
                        <span>Market + niche</span>
                    </div>
                    <div class="site-wizard-step" data-step="3">
                        <span class="wiz-num">3</span>
                        <span>Pricing + policies</span>
                    </div>
                </div>

                <!-- Step 1: Site basics -->
                <div class="wizard-pane active" data-wizard-pane="1">
                    <div class="form-section">
                        <span class="form-section-title">Identity</span>
                        <div class="row g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Site Name <span class="req" aria-hidden="true">*</span></label>
                                <input type="text" name="siteName" id="siteName" class="form-control" placeholder="Enter site name" value="{{ old_text('siteName') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Site URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="siteUrl" id="siteUrl" class="form-control" placeholder="eg:https://example.com" value="{{ old_text('siteUrl') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Example URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="exampleUrl" id="exampleUrl" class="form-control" placeholder="https://example.com/example" value="{{ old_text('exampleUrl') }}" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <span class="form-section-title">Authority metrics</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-3">
                                <label class="form-label">
                                    <abbr class="metric-abbr text-decoration-none" title="Moz Domain Authority — site strength score from 0–100">DA</abbr>
                                    (Domain Authority) <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="number" name="da" id="da" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old_text('da') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                    (Domain Rating) <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="number" name="dr" id="dr" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old_text('dr') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Traffic <span class="req" aria-hidden="true">*</span></label>
                                <input type="number" name="traffic" id="traffic" class="form-control" placeholder="e.g. 1500000" min="0" max="4294967295" step="1" inputmode="numeric" value="{{ old_text('traffic') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Turnaround Time <span class="req" aria-hidden="true">*</span></label>
                                @php $turnaroundDefault = old('turnaround_time', '3days'); @endphp
                                <select name="turnaround_time" id="turnaroundTime" class="form-select" required>
                                    <option value="24h" {{ $turnaroundDefault == '24h' ? 'selected' : '' }}>24 Hours</option>
                                    <option value="48h" {{ $turnaroundDefault == '48h' ? 'selected' : '' }}>48 Hours</option>
                                    <option value="3days" {{ $turnaroundDefault == '3days' ? 'selected' : '' }}>3 Days</option>
                                    <option value="5days" {{ $turnaroundDefault == '5days' ? 'selected' : '' }}>5 Days</option>
                                    <option value="7days" {{ $turnaroundDefault == '7days' ? 'selected' : '' }}>7 Days</option>
                                </select>
                                <div class="help-text">Estimated time to publish after order confirmation</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <span class="form-section-title">Description</span>
                        <div class="row">
                            <div class="col-12">
                                <label class="form-label">Site Description (500 words max) <span class="req" aria-hidden="true">*</span></label>
                                <div id="quillEditor" class="border rounded" style="height: 200px;">{{ old_text('siteDescription') }}</div>
                                <input type="hidden" name="siteDescription" id="siteDescription" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Market + niche -->
                <div class="wizard-pane" data-wizard-pane="2">
                    <div class="form-section">
                        <span class="form-section-title">Market & niche</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Language <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="language" id="selectedLanguage" value="{{ old_text('language', is_array(old('languages')) ? (old('languages')[0] ?? '') : old('languages')) }}">
                                <div class="single-select-wrapper" id="languageWrapper">
                                    <div class="single-select-input" id="languageInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select language">
                                        <span class="single-select-value" id="languageValue"><span class="single-select-placeholder">Select language...</span></span>
                                        <span class="single-select-arrow" aria-hidden="true">▾</span>
                                    </div>
                                    <div class="single-select-dropdown" id="languageDropdown">
                                        <div class="single-select-search">
                                            <input type="text" placeholder="Search languages..." id="languageSearch">
                                        </div>
                                        <div class="single-select-options" id="languageOptions">
                                            @foreach($languages as $language)
                                                <div class="single-select-option" data-value="{{ strtolower($language->code) }}" data-label="{{ $language->name }}">{{ $language->name }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Pick one language.
                                    <x-glass-tip
                                        title="Language → markets"
                                        body="Country options update to markets that match this language (e.g. German → DE, AT, CH)."
                                        label="Help: country options update to markets that match this language"
                                        placement="top"
                                    />
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Country / Market <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="country" id="selectedCountry" value="{{ old_text('country', is_array(old('countries')) ? (old('countries')[0] ?? '') : old('countries')) }}">
                                <div class="single-select-wrapper" id="countryWrapper">
                                    <div class="single-select-input" id="countryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select country or market">
                                        <span class="single-select-value" id="countryValue"><span class="single-select-placeholder">Select language first...</span></span>
                                        <span class="single-select-arrow" aria-hidden="true">▾</span>
                                    </div>
                                    <div class="single-select-dropdown" id="countryDropdown">
                                        <div class="single-select-search">
                                            <input type="text" placeholder="Search countries..." id="countrySearch">
                                        </div>
                                        <div class="single-select-options" id="countryOptions">
                                            @foreach($countries as $country)
                                                <div class="single-select-option" data-value="{{ strtolower($country->code) }}" data-label="{{ $country->name }}">{{ $country->name }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div id="relatedCountriesHint" class="mt-2 small text-muted"></div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    One country only.
                                    <x-glass-tip
                                        title="Country / Market"
                                        body="Matching markets are selectable. Other countries stay visible but faded."
                                        label="Help: matching markets are selectable"
                                        placement="top"
                                    />
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categories <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="categories" id="selectedCategories" value="{{ is_array(old('categories')) ? implode('|', old('categories')) : old('categories') }}">
                                <div class="multi-select-wrapper" id="categoryWrapper">
                                    <div class="multi-select-input" id="categoryInput">
                                        <span class="multi-select-placeholder">Select categories (max 7)...</span>
                                    </div>
                                    <div class="multi-select-dropdown" id="categoryDropdown">
                                        <div class="multi-select-search">
                                            <input type="text" placeholder="Search categories..." id="categorySearch">
                                        </div>
                                        <div class="multi-select-options" id="categoryOptions">
                                            @foreach($categories as $category)
                                                <div class="multi-select-option" data-value="{{ $category->name }}" data-label="{{ $category->name }}">{{ $category->name }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Topic niches for this market.
                                    <x-glass-tip
                                        title="Categories"
                                        body="Example: Tech for German / Austria. Pick up to 7 categories."
                                        label="Help: pick up to 7 topic categories for this market"
                                        placement="top"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Pricing + policies -->
                <div class="wizard-pane" data-wizard-pane="3">
                    <div class="form-section">
                        <span class="form-section-title">Pricing & link policy</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Price (€) <span class="req" aria-hidden="true">*</span></label>
                                <input type="number" name="price" id="price" class="form-control" placeholder="Enter price" min="0" step="0.01" value="{{ old_text('price') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Publication Duration <span class="req" aria-hidden="true">*</span></label>
                                <select name="publicationTime" id="publicationTime" class="form-select" required>
                                    <option value="">Select Duration</option>
                                    <option value="6months" {{ old('publicationTime') == '6months' ? 'selected' : '' }}>6 Months</option>
                                    <option value="1year" {{ old('publicationTime') == '1year' ? 'selected' : '' }}>1 Year</option>
                                    <option value="permanent" {{ old('publicationTime') == 'permanent' ? 'selected' : '' }}>Permanent</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Link Type <span class="req" aria-hidden="true">*</span></label>
                                <div class="d-flex gap-3 mt-2">
                                    <div class="form-check">
                                        <input type="radio" name="link_type" id="linkTypeDofollow" value="dofollow" class="form-check-input" {{ old('link_type', 'dofollow') == 'dofollow' ? 'checked' : '' }}>
                                        <label class="form-check-label">DoFollow</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="link_type" id="linkTypeNofollow" value="nofollow" class="form-check-input" {{ old('link_type') == 'nofollow' ? 'checked' : '' }}>
                                        <label class="form-check-label">NoFollow</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <span class="form-section-title">Tags</span>
                        @php
                            $oldTag = old('site_tag');
                            if ($oldTag === null) {
                                if (old('sponsored')) $oldTag = 'sponsored';
                                elseif (old('partner_material')) $oldTag = 'partner_material';
                                elseif (old('as_you_prefer')) $oldTag = 'as_you_prefer';
                                else $oldTag = '';
                            }
                        @endphp
                        <div class="row bg-light p-3 rounded g-3 g-form align-items-center">
                            <div class="col-12">
                                <label class="form-label mb-2">Optional — choose one</label>
                                <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Site tag">
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagNone" class="form-check-input" value="" {{ $oldTag === '' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagNone">None</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagSponsored" class="form-check-input" value="sponsored" {{ $oldTag === 'sponsored' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagSponsored">Sponsored</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagPartnerMaterial" class="form-check-input" value="partner_material" {{ $oldTag === 'partner_material' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagPartnerMaterial">Partner Materials</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagAsYouPrefer" class="form-check-input" value="as_you_prefer" {{ $oldTag === 'as_you_prefer' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagAsYouPrefer">As You Prefer</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        @php
                            $hasSensitiveOld = collect(['crypto','trading','CBD','forex'])->contains(fn ($t) => filled(old("sensitive.$t")) || filled(old("price_sensitive.$t")));
                        @endphp
                        <button type="button"
                                class="disclosure-toggle"
                                id="sensitiveDisclosureBtn"
                                aria-expanded="{{ $hasSensitiveOld ? 'true' : 'false' }}"
                                aria-controls="sensitiveDisclosurePanel">
                            <i class="fa fa-chevron-{{ $hasSensitiveOld ? 'down' : 'right' }}" aria-hidden="true"></i>
                            Sensitive topics (optional)
                        </button>
                        <p class="small text-muted mb-0 mt-1">Only open if you accept crypto, trading, CBD, or forex placements.</p>
                        <div class="disclosure-panel" id="sensitiveDisclosurePanel" @unless($hasSensitiveOld) hidden @endunless>
                            <div class="row bg-light p-3 rounded mt-2">
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach(['crypto','trading','CBD','forex'] as $topic)
                                        <div class="me-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="sensitive[{{ $topic }}]" class="form-check-input sensitive-checkbox" id="sensitive{{ $topic }}" {{ old("sensitive.$topic") ? 'checked' : '' }}>
                                                <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                            </div>
                                            <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 sensitive-price" placeholder="Extra price (€)" value="{{ old_text("price_sensitive.$topic") }}">
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wizard-nav">
                    <div>
                        <button type="button" class="btn btn-cta-secondary shadow-sm d-none" id="wizardBackBtn">Back</button>
                        <span class="wizard-draft-hint ms-2" id="wizardDraftHint"></span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-cta-tertiary shadow-sm" id="closeBtn">Close</button>
                        <button type="button" class="btn btn-primary shadow-sm" id="wizardNextBtn">Next</button>
                        <button type="submit" class="btn btn-primary shadow-sm d-none" id="submitBtn">Review &amp; submit</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    {{-- Last look before the listing goes to review. The wizard splits the form
         across three panes, so until now nobody ever saw the whole thing at
         once — and a wrong price or country is only cheap to fix before staff
         start reviewing it. --}}
    <div class="modal fade" id="sitePreviewModal" tabindex="-1" aria-labelledby="sitePreviewLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sitePreviewLabel">Check your listing before you submit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="sitePreviewBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cta-secondary" data-bs-dismiss="modal" id="sitePreviewBackBtn">Back to edit</button>
                    <button type="button" class="btn btn-primary" id="sitePreviewConfirmBtn">Looks right — submit</button>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h4 class="mb-0">Your Sites</h4>
            <div class="d-inline-flex flex-wrap align-items-center gap-2" role="group" aria-label="Filter sites by status">
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter is-active" data-status="active" id="sitesFilterActive" aria-pressed="true">
                        <span class="filter-main">
                            Active <span class="badge text-bg-secondary" id="sitesActiveCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Active"
                        body="Approved / live sites on your panel."
                        label="What Active means"
                        placement="top"
                    />
                </div>
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter" data-status="pending" id="sitesFilterPending" aria-pressed="false">
                        <span class="filter-main">
                            Pending <span class="badge text-bg-secondary" id="sitesPendingCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Pending"
                        body="Bulk drafts with the marketer, sites that need your details, and listings waiting for admin approval."
                        label="What Pending means"
                        placement="top"
                    />
                </div>
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter" data-status="invites" id="sitesFilterInvites" aria-pressed="false">
                        <span class="filter-main">
                            Invites <span class="badge text-bg-secondary" id="sitesInviteCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Invites"
                        body="Sites our team added for you. Accept to show them in My Sites, or decline to remove them."
                        label="What Invites means"
                        placement="top"
                    />
                </div>
            </div>
        </div>
        <p class="small text-muted mb-2" id="sitesFilterHint">Approved and live sites on your panel.</p>
        <input type="text" id="siteSearch" class="form-control table-search" placeholder="Search sites...">
        <div id="sitesTableWrapper" class="mt-3"></div>
    </div>
</div>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="{{ asset('assets/js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('assets/js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@php
    $pwOldLanguage = old('language', is_array(old('languages')) ? (old('languages')[0] ?? null) : old('languages'));
    $pwOldCountry = old('country', is_array(old('countries')) ? (old('countries')[0] ?? null) : old('countries'));
    $pwOldCategories = old('categories', []);
@endphp
<script>
window.PublisherWebsitesConfig = {
    csrfToken: @json(csrf_token()),
    maxBulkRows: {{ (int) \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }},
    openBulkRequestModal: @json((bool) session('open_bulk_request_modal')),
    languageCountryMap: @json($languageCountryMap ?? new \stdClass()),
    old: {
        language: @json($pwOldLanguage ? strtolower((string) $pwOldLanguage) : null),
        country: @json($pwOldCountry ? strtolower((string) $pwOldCountry) : null),
        categories: @json($pwOldCategories),
    },
    routes: {
        ajax: @json(route('publisher.sites.ajax')),
        store: @json(route('publisher.sites.store')),
        login: @json(route('login')),
        balance: @json(route('publisher.balance')),
        promotionsWallet: @json(route('publisher.promotions.wallet')),
    },
};
</script>
<script src="{{ asset('assets/js/publisher-websites-bulk.js') }}?v={{ @filemtime(public_path('assets/js/publisher-websites-bulk.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/publisher-websites.js') }}?v={{ @filemtime(public_path('assets/js/publisher-websites.js')) ?: '1' }}"></script>

@endsection