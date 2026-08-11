@extends('advertiser.layouts.app')

@section('content')
@php
    $filterBase = $libraryFilterBase ?? [
        'status' => $statusFilter ?? 'all',
        'availability' => $availabilityFilter ?? 'all',
        'language' => $languageFilter ?? 'all',
        'country' => $countryFilter ?? 'all',
        'q' => $searchQuery ?? '',
    ];
    $libraryRoute = function (array $overrides = []) use ($filterBase) {
        $params = array_merge($filterBase, $overrides);
        if (($params['q'] ?? '') === '') {
            unset($params['q']);
        }
        return route('advertiser.content-library', $params);
    };
    $statusLabels = [
        'available' => 'Approved',
        'evaluating' => 'Evaluating',
        'in_progress' => 'In progress',
        'published' => 'Completed/LIVE',
        'needs_fix' => 'Needs corrections',
        'expired' => 'Expired',
        'archived' => 'Archived',
        'unavailable' => 'Pending',
    ];
    $moderationCounts = $moderationCounts ?? [
        'all' => 0,
        'approved' => 0,
        'rejected' => 0,
        'needs_fix' => 0,
    ];
    $availabilityCounts = $availabilityCounts ?? [
        'all' => 0,
        'available' => 0,
        'evaluating' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'expired' => 0,
        'archived' => 0,
        'needs_fix' => 0,
    ];
    $uploadsEnabled = $uploadsEnabled ?? true;
    $evaluatingCount = (int) ($availabilityCounts['evaluating'] ?? 0);
    // Status strip: Approved (+ Evaluating badge) · In progress · Needs corrections · Completed/LIVE · Archived · Expired
    $libraryStatusChips = [
        'approved' => [
            'label' => 'Approved',
            'count' => (int) ($availabilityCounts['available'] ?? 0),
            'params' => ['status' => 'approved', 'availability' => 'available'],
            'evaluating' => $evaluatingCount,
        ],
        'in_progress' => [
            'label' => 'In progress',
            'count' => (int) ($availabilityCounts['in_progress'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'in_progress'],
        ],
        'needs_fix' => [
            'label' => 'Needs corrections',
            'count' => (int) ($moderationCounts['needs_fix'] ?? $availabilityCounts['needs_fix'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'needs_fix'],
        ],
        'completed' => [
            'label' => 'Completed/LIVE',
            'count' => (int) ($availabilityCounts['completed'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'completed'],
        ],
        'archived' => [
            'label' => 'Archived',
            'count' => (int) ($availabilityCounts['archived'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'archived'],
        ],
        'expired' => [
            'label' => 'Expired',
            'count' => (int) ($availabilityCounts['expired'] ?? 0),
            'params' => ['status' => 'all', 'availability' => 'expired'],
        ],
    ];
    $activeLibraryChip = 'approved';
    if (($availabilityFilter ?? 'all') === 'completed') {
        $activeLibraryChip = 'completed';
    } elseif (($availabilityFilter ?? 'all') === 'in_progress') {
        $activeLibraryChip = 'in_progress';
    } elseif (($availabilityFilter ?? 'all') === 'needs_fix'
        || ($statusFilter ?? 'all') === 'rejected') {
        $activeLibraryChip = 'needs_fix';
    } elseif (($availabilityFilter ?? 'all') === 'archived') {
        $activeLibraryChip = 'archived';
    } elseif (($availabilityFilter ?? 'all') === 'expired') {
        $activeLibraryChip = 'expired';
    } elseif (($availabilityFilter ?? 'all') === 'available' || ($statusFilter ?? 'all') === 'approved') {
        $activeLibraryChip = 'approved';
    }
    $libraryStatusDisplay = function (string $availability, string $moderationStatus = '') use ($statusLabels): array {
        $category = match ($availability) {
            'published' => 'completed',
            'needs_fix' => 'needs_fix',
            'in_progress' => 'in_progress',
            'available' => 'approved',
            'evaluating' => 'evaluating',
            'expired' => 'expired',
            'archived' => 'archived',
            default => 'pending',
        };
        $label = match ($category) {
            'completed' => 'Completed/LIVE',
            'needs_fix' => 'Needs corrections',
            'in_progress' => 'In progress',
            'approved' => 'Approved',
            'evaluating' => 'Evaluating',
            'expired' => 'Expired',
            'archived' => 'Archived',
            default => ($moderationStatus === 'pending' || $moderationStatus === 'processing')
                ? 'Evaluating'
                : ($statusLabels[$availability] ?? 'Pending'),
        };

        return ['category' => $category, 'label' => $label];
    };
@endphp
<link href="{{ asset('assets/css/content-library.css') }}?v={{ @filemtime(public_path('assets/css/content-library.css')) ?: '1' }}" rel="stylesheet">


<div class="container-fluid">
    @include('advertiser.partials.ordering-path', [
        'step' => 3,
        'title' => 'Place a guest post · Content',
        'subtitle' => 'One job here: upload and approve articles. Any approved article can be placed on any catalog site.',
        'linkAll' => true,
        'contentRoute' => route('advertiser.content-library'),
        'actions' => '<a href="'.e(route('advertiser.catalog')).'" class="btn btn-sm btn-outline-secondary">Browse publishers</a>',
    ])

    <div class="mb-3">
        <h2 class="mb-1 fw-semibold">Content Library</h2>
        <p class="text-muted mb-0 small">
            Upload a .docx (choose language and country yourself) → wait for approval → browse any publishers → assign in cart → pay.
            Multi-site orders need a different approved article for each website — language does not have to match the site.
        </p>
        <div class="library-page-actions upload-zone">
            @if($uploadsEnabled)
                <button type="button" class="btn btn-upload" data-bs-toggle="modal" data-bs-target="#uploadContentModal" id="openUploadModalBtn">
                    <i class="fa fa-upload me-1"></i> Upload article
                </button>
            @else
                <button type="button" class="btn btn-upload" id="openUploadModalBtn" disabled title="Uploads are temporarily turned off">
                    <i class="fa fa-upload me-1"></i> Uploads disabled
                </button>
            @endif
            <a href="{{ route('advertiser.catalog') }}" class="btn btn-outline-primary btn-sm" id="libraryBrowsePublishersBtn">
                <i class="fa fa-store me-1" aria-hidden="true"></i> Browse publishers
            </a>
            <span class="small text-muted mb-0">.docx only · pick language &amp; country before upload · use Order on a row to place an approved article</span>
        </div>
    </div>

    <div id="libraryFlash" class="alert d-none" role="status"></div>
    @if($evaluatingCount > 0 && ($activeLibraryChip ?? '') === 'approved')
        <div class="alert alert-info py-2 px-3 small mb-3" role="status">
            <i class="fa fa-spinner fa-spin me-1" aria-hidden="true"></i>
            {{ $evaluatingCount }} article{{ $evaluatingCount === 1 ? '' : 's' }} still evaluating — Order unlocks when approved.
        </div>
    @endif
    @if(($nearExpiryCount ?? 0) > 0)
        <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
            <i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>
            {{ $nearExpiryCount }} unused article{{ $nearExpiryCount === 1 ? '' : 's' }}
            expire{{ $nearExpiryCount === 1 ? 's' : '' }} within {{ (int) ($nearExpiryDays ?? 7) }} days.
            Order or download them before automatic purge removes unused expired files
            ({{ (int) ($retentionMonths ?? 6) }}-month retention — articles linked to orders are never purged).
        </div>
    @endif
    @unless($uploadsEnabled)
        <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
            New uploads are temporarily turned off. You can still browse, archive, and order approved articles.
        </div>
    @endunless

    <form method="GET" action="{{ route('advertiser.content-library') }}" class="library-filter-bar row g-2 align-items-end mb-2">
        <input type="hidden" name="status" value="{{ $statusFilter ?? 'all' }}">
        <input type="hidden" name="availability" value="{{ $availabilityFilter ?? 'all' }}">
        <div class="col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1" for="librarySearchInput">Search</label>
            <input type="search" name="q" id="librarySearchInput" class="form-control form-control-sm"
                   value="{{ $searchQuery ?? '' }}" placeholder="Title or filename"
                   title="Results update as you type" autocomplete="off" enterkeyhint="search"
                   data-slb-live-search="form">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Country</label>
            <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" @selected(($countryFilter ?? 'all') === 'all')>All</option>
                @foreach(($groupedByCountry ?? []) as $countryCode => $count)
                    <option value="{{ $countryCode }}" @selected(($countryFilter ?? 'all') === $countryCode)>
                        {{ strtoupper($countryCode) }} ({{ $count }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Language</label>
            <select name="language" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" @selected(($languageFilter ?? 'all') === 'all')>All</option>
                @foreach(($groupedByLanguage ?? []) as $langCode => $count)
                    <option value="{{ $langCode }}" @selected(($languageFilter ?? 'all') === $langCode)>
                        {{ strtoupper($langCode) }} ({{ $count }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            @if(!empty($searchQuery) || ($activeLibraryChip ?? 'approved') !== 'approved' || ($countryFilter ?? 'all') !== 'all' || ($languageFilter ?? 'all') !== 'all')
                <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-link">Reset</a>
            @endif
        </div>
    </form>

    <div class="library-status-row" role="group" aria-label="Library status filter">
        @foreach($libraryStatusChips as $key => $chip)
            <a href="{{ $libraryRoute($chip['params']) }}"
               class="library-status-box library-status-box--{{ $key }} @if($activeLibraryChip === $key) is-active @endif"
               @if($activeLibraryChip === $key) aria-current="true" @endif>
                <span class="library-status-box__main">
                    <span>{{ $chip['label'] }}</span>
                    @if($key === 'approved' && (int) ($chip['evaluating'] ?? 0) > 0)
                        <span class="library-eval-badge" title="Articles still being checked">
                            Evaluating {{ (int) $chip['evaluating'] }}
                        </span>
                    @endif
                </span>
                <span class="mod-count">{{ $chip['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="library-table border shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Market</th>
                        <th>Status</th>
                        <th>
                            <span class="library-scores-head">
                                Scores
                                <x-glass-tip
                                    title="Advisory scores"
                                    body="Uniqueness and quality are advisory. Approved articles can still be ordered even when a score is below the warn threshold. Policy and clear language mismatches can block approval."
                                    label="About scores"
                                    placement="bottom"
                                />
                            </span>
                        </th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($submissions as $submission)
                    @php
                        $availability = $submission->libraryAvailability();
                        $placement = $submission->placementItem();
                        $liveUrl = $submission->liveUrl();
                        $siteName = $placement?->site_name
                            ?: $placement?->site?->site_name
                            ?: null;
                        $publishedAt = $placement?->live_url_submitted_at
                            ?: ($liveUrl ? $placement?->updated_at : null);
                        $publishedDateLabel = $publishedAt
                            ? $publishedAt->timezone(config('app.timezone'))->format('M j, Y')
                            : null;
                        // Align Status column with filter chips: Approved · Needs corrections · Completed/LIVE
                        $statusDisplay = $libraryStatusDisplay($availability, (string) $submission->moderation_status);
                        $label = $statusDisplay['label'];
                        $statusCategory = $statusDisplay['category'];
                    @endphp
                    <tr id="library-row-{{ $submission->id }}" @class(['library-row--completed' => $availability === 'published'])>
                        <td>
                            @if($submission->feature_image_url)
                                <img src="{{ \App\Services\ContentUpload\ArticlePreviewHtml::normalizeSrc((string) $submission->feature_image_url) }}"
                                     alt=""
                                     class="library-feature-thumb"
                                     loading="lazy"
                                     onerror="this.style.display='none'; this.insertAdjacentHTML('afterend','<span class=\'text-muted small\'>Image unavailable</span>');">
                            @endif
                            <div class="library-title text-truncate" data-title-display="{{ $submission->id }}" title="{{ $submission->title ?: $submission->original_filename }}">
                                {{ $submission->title ?: $submission->original_filename }}
                            </div>
                            @if($availability === 'published')
                                <div class="library-live-link">
                                    <div class="library-pub-details">
                                        @if($siteName)
                                            <div><strong>Published on:</strong> {{ $siteName }}</div>
                                        @else
                                            <div><strong>Status:</strong> Placement completed</div>
                                        @endif
                                        @if($submission->order_id)
                                            <div><strong>Order:</strong> #{{ $submission->order_id }}</div>
                                        @endif
                                        @if($placement?->price !== null)
                                            <div><strong>Price:</strong> €{{ number_format((float) $placement->price, 2) }}</div>
                                        @endif
                                        @if($publishedDateLabel)
                                            <div><strong>Published:</strong> {{ $publishedDateLabel }}</div>
                                        @endif
                                    </div>
                                    @if($liveUrl)
                                        <div class="library-live-actions">
                                            <a class="library-live-url" href="{{ $liveUrl }}" target="_blank" rel="noopener noreferrer">
                                                {{ $liveUrl }} <i class="fa fa-external-link fa-xs" aria-hidden="true"></i>
                                            </a>
                                            <button type="button"
                                                    class="library-copy-url"
                                                    data-copy-url="{{ $liveUrl }}"
                                                    onclick="copyLibraryLiveUrl(this)"
                                                    title="Copy to clipboard"
                                                    aria-label="Copy live URL to clipboard">
                                                <i class="fa fa-copy" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    @else
                                        <div class="library-live-meta mt-1">Live URL not available</div>
                                    @endif
                                </div>
                            @elseif($availability === 'in_progress' && $submission->order_id)
                                <div class="library-live-link text-muted">
                                    Order #{{ $submission->order_id }}
                                    @if($siteName) · {{ $siteName }} @endif
                                </div>
                            @elseif($availability === 'needs_fix')
                                <div class="library-reject-box">
                                    <span class="library-reject-box__icon" aria-hidden="true"><i class="fa-solid fa-circle-exclamation"></i></span>
                                    <div>
                                    <strong>{{ $label }}</strong>
                                    {{ $submission->evaluation_report['summary'] ?? 'Fix issues and resubmit.' }}
                                    @php
                                        $reasonGroups = $submission->evaluationReasonGroups();
                                        $hitTerms = $submission->evaluation_report['matched_terms'] ?? [];
                                        $blockedUrls = $submission->evaluation_report['blocked_urls'] ?? [];
                                    @endphp
                                    @if(($reasonGroups['blocking'] ?? []) !== [])
                                        <span class="library-reason-label">Blocking</span>
                                        <ul class="library-reason-list library-reason-list--blocking">
                                            @foreach(array_slice($reasonGroups['blocking'], 0, 5) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if(($reasonGroups['advisory'] ?? []) !== [])
                                        <span class="library-reason-label">Advisory</span>
                                        <ul class="library-reason-list library-reason-list--advisory">
                                            @foreach(array_slice($reasonGroups['advisory'], 0, 5) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if(is_array($hitTerms) && count($hitTerms))
                                        <div class="mt-1">Remove/rewrite: {{ implode(', ', array_slice($hitTerms, 0, 8)) }}</div>
                                    @endif
                                    @if(is_array($blockedUrls) && count($blockedUrls))
                                        <div class="mt-1">Blocked links: {{ implode(', ', array_slice($blockedUrls, 0, 5)) }}</div>
                                    @endif
                                    </div>
                                </div>
                            @elseif($availability === 'available' && $submission->expires_at)
                                @php
                                    $daysLeft = $submission->daysUntilExpiry();
                                    $near = $submission->isNearExpiry((int) ($nearExpiryDays ?? 7));
                                @endphp
                                @if($daysLeft !== null)
                                    <div @class(['library-expiry-hint', 'library-expiry-hint--urgent' => $near])>
                                        @if($daysLeft <= 0)
                                            Expires today
                                        @elseif($daysLeft === 1)
                                            Expires in 1 day
                                        @else
                                            Expires in {{ $daysLeft }} days
                                        @endif
                                        <span class="text-muted">· unused files are purged after expiry</span>
                                    </div>
                                @endif
                            @endif
                            @if($availability !== 'published')
                            <div class="library-title-edit d-none mt-2" data-title-edit="{{ $submission->id }}">
                                <div class="input-group input-group-sm" style="max-width:320px;">
                                    <input type="text" class="form-control" maxlength="200"
                                           value="{{ $submission->title }}"
                                           data-title-input="{{ $submission->id }}">
                                    <button type="button" class="btn btn-primary" onclick="saveLibraryTitle({{ $submission->id }})">Save</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="toggleLibraryTitleEdit({{ $submission->id }}, false)">Cancel</button>
                                </div>
                            </div>
                            @endif
                        </td>
                        <td>
                            <span class="library-market">
                                {{ strtoupper((string) $submission->country) }}/{{ strtoupper((string) $submission->language) }}
                            </span>
                        </td>
                        <td>
                            <div class="library-status-wrap">
                                <span class="library-status library-status--{{ $statusCategory }}">{{ $label }}</span>
                                @if($statusCategory === 'completed')
                                    <span class="library-status-hint">Done — not orderable</span>
                                @elseif($availability === 'in_progress')
                                    <span class="library-status-hint">In placement</span>
                                @endif
                            </div>
                        </td>
                        <td class="library-scores">
                            @if($submission->evaluated_at)
                                {{ $submission->uniqueness_score !== null ? $submission->uniqueness_score.'%' : '—' }}
                                ·
                                {{ $submission->quality_score !== null ? $submission->quality_score.'%' : '—' }}
                                @php
                                    $minU = (int) (($uploadCfg['evaluation']['min_uniqueness'] ?? 50));
                                    $minQ = (int) (($uploadCfg['evaluation']['min_quality'] ?? 50));
                                    $scoresAdvisory = ($submission->uniqueness_score !== null && $submission->uniqueness_score < $minU)
                                        || ($submission->quality_score !== null && $submission->quality_score < $minQ);
                                @endphp
                                @if($scoresAdvisory && $submission->moderation_status === \App\Models\ContentSubmission::STATUS_APPROVED)
                                    <span class="library-scores-note">Advisory — still orderable</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end library-actions">
                            @if($availability === 'published')
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static"
                                            data-bs-auto-close="true" aria-expanded="false" aria-haspopup="true">
                                        More
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end library-more-menu">
                                        @if($submission->preview_html)
                                            <li>
                                                <button type="button" class="dropdown-item js-open-preview"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Preview
                                                </button>
                                            </li>
                                        @endif
                                        <li>
                                            <a class="dropdown-item" href="{{ route('advertiser.content-submissions.download', $submission) }}">Download</a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            @else
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                @if($submission->canBeOrdered())
                                    <a class="btn btn-sm btn-primary"
                                       href="{{ route('advertiser.content-library.order', $submission) }}">
                                        Order
                                    </a>
                                @elseif($availability === 'evaluating')
                                    <span class="small text-muted">
                                        <i class="fa fa-spinner fa-spin me-1" aria-hidden="true"></i>Evaluating…
                                    </span>
                                @elseif($availability === 'needs_fix')
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('advertiser.content-library', ['edit' => $submission->id, 'upload' => 1]) }}">
                                        Resubmit
                                    </a>
                                @elseif($availability === 'in_progress')
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('advertiser.orders') }}">View order</a>
                                @endif

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static"
                                            data-bs-auto-close="true" aria-expanded="false" aria-haspopup="true">
                                        More
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end library-more-menu">
                                        @if($submission->preview_html)
                                            <li>
                                                <button type="button" class="dropdown-item js-open-preview"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Preview
                                                </button>
                                            </li>
                                        @endif
                                        @if(!$submission->isInUse() && !$submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item js-open-editor"
                                                        data-submission-id="{{ $submission->id }}">
                                                    Edit article
                                                </button>
                                            </li>
                                        @endif
                                        <li>
                                            <a class="dropdown-item" href="{{ route('advertiser.content-submissions.download', $submission) }}">Download</a>
                                        </li>
                                        @if(!$submission->isInUse() && !$submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="toggleLibraryTitleEdit({{ $submission->id }}, true)">Rename</button>
                                            </li>
                                        @endif
                                        @if($submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="restoreLibraryArticle({{ $submission->id }})">Restore</button>
                                            </li>
                                        @elseif($availability !== 'in_progress')
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="archiveLibraryArticle({{ $submission->id }})">Archive</button>
                                            </li>
                                        @endif
                                        @if(!$submission->isInUse())
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        onclick="deleteLibraryArticle({{ $submission->id }}, @js($submission->title ?: $submission->original_filename))">
                                                    Delete
                                                </button>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            @php
                                $libraryTotalArticles = (int) ($moderationCounts['all'] ?? 0);
                                $hasActiveSearchOrFacet = ! empty($searchQuery)
                                    || (($countryFilter ?? 'all') !== 'all')
                                    || (($languageFilter ?? 'all') !== 'all');
                            @endphp
                            @if($libraryTotalArticles < 1 && ! $hasActiveSearchOrFacet && ($availabilityFilter ?? 'available') === 'available')
                                <x-ui.empty-state
                                    icon="fa-file-word"
                                    title="No articles yet"
                                    message="Upload a .docx here. After approval, assign it in your cart and checkout."
                                >
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        @if($uploadsEnabled)
                                            <button type="button" class="btn btn-upload" data-bs-toggle="modal" data-bs-target="#uploadContentModal">
                                                <i class="fa fa-upload me-1"></i> Upload article
                                            </button>
                                        @endif
                                        <a href="{{ route('advertiser.wizard.start') }}" class="btn btn-outline-secondary">
                                            Guided placement
                                        </a>
                                    </div>
                                </x-ui.empty-state>
                            @elseif(($availabilityFilter ?? 'all') === 'archived')
                                <x-ui.empty-state
                                    icon="fa-box-archive"
                                    title="No archived articles"
                                    message="Archive unused approved articles from the More menu. Restore anytime to order again."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'expired')
                                <x-ui.empty-state
                                    icon="fa-hourglass-end"
                                    title="No expired articles"
                                    message="Unused articles past their retention date appear here. Automatic purge deletes unused expired files only — articles linked to orders are never removed."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'completed')
                                <x-ui.empty-state
                                    icon="fa-check-circle"
                                    title="No completed articles yet"
                                    message="They’ll appear here with their live URL once a placement is published."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'in_progress')
                                <x-ui.empty-state
                                    icon="fa-clock"
                                    title="No articles in progress"
                                    message="After you Order an approved article, it stays here until the publisher posts the live URL."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'needs_fix'
                                || ($statusFilter ?? 'all') === 'rejected')
                                <x-ui.empty-state
                                    icon="fa-pen-to-square"
                                    title="No articles need corrections"
                                    message="Rejected or scan-error articles will show here so you can revise and resubmit."
                                />
                            @elseif(($availabilityFilter ?? 'all') === 'available' || ($statusFilter ?? 'all') === 'approved')
                                <x-ui.empty-state
                                    icon="fa-circle-check"
                                    title="No approved articles ready to order"
                                    message="Approved articles available for publication will show here. Mid-evaluation uploads also appear on this tab."
                                />
                            @elseif($hasActiveSearchOrFacet || ($availabilityFilter ?? 'all') !== 'all')
                                No articles match these filters.
                            @else
                                <x-ui.empty-state
                                    icon="fa-file-word"
                                    title="No articles yet"
                                    message="Upload a .docx here. After approval, assign it in your cart and checkout."
                                >
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        @if($uploadsEnabled)
                                            <button type="button" class="btn btn-upload" data-bs-toggle="modal" data-bs-target="#uploadContentModal">
                                                <i class="fa fa-upload me-1"></i> Upload article
                                            </button>
                                        @endif
                                        <a href="{{ route('advertiser.wizard.start') }}" class="btn btn-outline-secondary">
                                            Guided placement
                                        </a>
                                    </div>
                                </x-ui.empty-state>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $submissions->links() }}</div>
</div>

{{-- Upload modal --}}
<div class="modal fade" id="uploadContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="libraryUploadForm">
            <div class="modal-header">
                <h5 class="modal-title">Upload article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <x-ui.callout variant="attention" class="ui-callout--sm mb-3">
                    {{ $uploadCfg['help']['preferred_format'] ?? 'Please upload your article as a Microsoft Word (.docx) document only.' }}
                    After upload you can preview and edit the article (add/remove images and links) before ordering.
                </x-ui.callout>
                <div class="mb-3">
                    <label class="form-label">Title <span class="text-muted">(optional)</span></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="Article title"
                           value="{{ $editSubmission->title ?? '' }}">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Language <span class="text-danger">*</span></label>
                        <select name="language" id="libraryLanguage" class="form-select" required>
                            <option value="">Select language</option>
                            @foreach(($languages ?? []) as $language)
                                <option value="{{ strtolower($language->code) }}"
                                    @selected(strtolower((string) ($editSubmission->language ?? '')) === strtolower($language->code))>
                                    {{ $language->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Article text must match this language (e.g. German text for German). English is allowed when English is selected.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Country <span class="text-danger">*</span></label>
                        <select name="country" id="libraryCountry" class="form-select" required disabled>
                            <option value="">Select language first</option>
                        </select>
                        <div class="form-text">Countries update to markets that match the language.</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Microsoft Word document (.docx)</label>
                    <input type="file" name="file" id="libraryFileInput" class="form-control" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>

                @include('advertiser.partials.image-rights-declaration', [
                    'idPrefix' => 'libraryImageRights',
                    'submission' => $editSubmission ?? null,
                ])

                <input type="hidden" name="replace_id" id="replaceIdInput" value="{{ $editSubmission->id ?? '' }}">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-upload" id="libraryUploadBtn">Upload &amp; preview</button>
            </div>
        </form>
    </div>
</div>

{{-- Docs-style editor modal --}}
<div class="modal fade" id="articleEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Edit article</h5>
                    <div class="article-editor-meta" id="articleEditorMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="articleEditorTitle" maxlength="200" placeholder="Article title">
                </div>
                <div class="alert alert-light border small mb-3">
                    Edit like a document: format text, insert or remove images, and add or remove links. Saving re-checks the article for approval.
                </div>
                <div class="article-docs-shell mb-3">
                    <div id="articleQuillEditor"></div>
                </div>

                {{-- Shown when the article gains images the current declaration does not cover. --}}
                <div id="articleEditorImageRights" class="border rounded-3 p-3 mb-3 d-none">
                    <div class="fw-semibold small mb-2">This article contains images</div>
                    @include('advertiser.partials.image-rights-declaration', [
                        'idPrefix' => 'editorImageRights',
                        'submission' => null,
                    ])
                </div>

                <div id="articleEditorFeedback" class="small" aria-live="polite"></div>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="articleEditorPreviewBtn">Preview</button>
                <button type="button" class="btn btn-primary" id="articleEditorSaveBtn">Save &amp; re-check</button>
                <a href="#" class="btn btn-success d-none" id="articleEditorOrderBtn">Order</a>
            </div>
        </div>
    </div>
</div>

{{-- Full preview modal --}}
<div class="modal fade" id="articlePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header flex-wrap gap-2">
                <div class="me-auto">
                    <h5 class="modal-title mb-0" id="articlePreviewTitle">Article preview</h5>
                    <div class="small text-muted" id="articlePreviewHeadingHint"></div>
                </div>
                <div class="article-preview-toolbar d-flex flex-wrap gap-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-copy-icon"
                            id="articleCopyHeadingBtn"
                            title="Copy heading to clipboard"
                            aria-label="Copy heading to clipboard">
                        <i class="fa fa-copy" aria-hidden="true"></i>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-copy-icon"
                            id="articleCopyContentBtn"
                            title="Copy article to clipboard"
                            aria-label="Copy article to clipboard">
                        <i class="fa fa-clone" aria-hidden="true"></i>
                    </button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="library-preview" style="max-height:none;" id="articlePreviewBody"></div>
                <div id="articlePreviewLinkMeta" class="border-top mt-3 pt-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div class="fw-semibold">Links in this article</div>
                        <button type="button" class="btn btn-sm btn-primary d-none" id="articleLinksSaveBtn">Save link edits</button>
                    </div>
                    <div id="articlePreviewLinksList"></div>
                    <p class="small text-muted mb-0 mt-2" id="articleLinksHelp">Shown outside the article so you can review every anchor and URL.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="{{ asset('assets/js/article-preview-tools.js') }}?v={{ @filemtime(public_path('assets/js/article-preview-tools.js')) ?: '1' }}"></script>
<script>
window.ContentLibraryBoot = {
    libraryUpdateUrl: @json(url('/advertiser/content-submissions')),
    libraryContentUrl: @json(url('/advertiser/content-submissions')),
    libraryImageUploadUrl: @json(route('advertiser.content-submissions.editor-image')),
    libraryOrderUrlBase: @json(url('/advertiser/content-library')),
    libraryPreviewUrlBase: @json(url('/advertiser/content-submissions')),
    libraryCsrf: @json(csrf_token()),
    libraryLanguageCountryMap: @json($languageCountryMap ?? new \stdClass()),
    libraryPreferredCountry: @json(strtolower((string) ($editSubmission->country ?? ''))),
    uploadsEnabled: @json(!empty($uploadsEnabled)),
    openUpload: @json(!empty($openUpload)),
    uploadUrl: @json(route('advertiser.content-library.upload')),
    libraryIndexUrl: @json(route('advertiser.content-library')),
    editSubmission: @json($editSubmissionBoot ?? null),
};
</script>
<script src="{{ asset('assets/js/content-library.js') }}?v={{ @filemtime(public_path('assets/js/content-library.js')) ?: '1' }}" defer></script>

@endsection
