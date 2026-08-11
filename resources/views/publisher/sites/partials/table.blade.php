@php
    $pendingOutgoingClaims = $pendingOutgoingClaims ?? collect();
    $status = $status ?? 'all';

    if (! function_exists('publisherSitesCountryFlag')) {
        function publisherSitesCountryFlag($countryCode)
        {
            $code = strtoupper(trim((string) $countryCode));
            if (strlen($code) !== 2) {
                return '';
            }
            if ($code === 'UK') {
                $code = 'GB';
            }

            return mb_convert_encoding('&#'.(127397 + ord($code[0])).';&#'.(127397 + ord($code[1])).';', 'UTF-8', 'HTML-ENTITIES');
        }
    }

    if (! function_exists('publisherSitesCategoryList')) {
        function publisherSitesCategoryList($site): array
        {
            if (is_array($site->categories) && count($site->categories)) {
                return array_values(array_filter(array_map('trim', $site->categories)));
            }

            return array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $site->category) ?: [])));
        }
    }
@endphp

@if($pendingOutgoingClaims->count() > 0)
    <div class="alert alert-warning py-2 mb-3">
        <strong>Pending ownership claims:</strong>
        @foreach($pendingOutgoingClaims as $claim)
            <span class="badge bg-warning text-dark me-1">{{ $claim->domain }}</span>
        @endforeach
        <span class="small text-muted ms-1">We’ll email you after review.</span>
    </div>
@endif

@if($sites->count() > 0)
<style>
    /* overflow:visible — overflow:hidden clipped Status/Price/Actions when Category grew wide */
    .modern-table { border-radius: 12px; overflow: visible; border: 1px solid #eee; text-align: center; border-collapse: separate; border-spacing: 0; }
    .modern-table th, .modern-table td { vertical-align: middle !important; }
    .modern-table thead { background: #343a40; color: #fff; text-align: center; }
    .modern-table thead th { background: #343a40; color: #fff; font-weight: 600; }
    .modern-table tbody tr:hover { background: #f7fbff; }
    .expand-row td { padding: 0 !important; overflow: hidden; }
    .expand-box { padding: 0 18px; max-height: 0; opacity: 0; overflow: hidden; transition: all 0.3s ease-in-out; }
    .expand-row.expanded .expand-box { padding: 18px; max-height: 800px; opacity: 1; }
    .detail-line { margin-bottom: 8px; font-size: 14px; }
    .tag-badge { background: #eef6ff; color: #0b6266; padding: 5px 10px; border-radius: 6px; font-size: 12px; margin-right: 6px; display: inline-block; }
    .sensitive-badge { background: #fff3cd; color: #856404; padding: 5px 10px; border-radius: 6px; font-size: 12px; margin-right: 6px; display: inline-block; }
    .desc-box { margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #eee; border-radius: 8px; }
    .category-chip { display: inline-block; background: #eef7f7; color: #0b6266; border-radius: 6px; padding: 2px 8px; font-size: 11px; margin: 1px; max-width: 100%; white-space: normal; }
    .pending-meta { font-size: 11px; color: #64748b; margin-top: 4px; }
    .turnaround-badge { display: inline-block; padding: 5px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; background-color: #f1f1f1; color: #282828; }
</style>

<table class="table table-striped modern-table sites-responsive-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Site Name</th>
            <th>URL</th>
            <th>Category</th>
            <th>DA</th>
            <th>DR</th>
            <th>Traffic</th>
            <th>Country / Language</th>
            <th>Status</th>
            <th>Price (€)</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sites as $index => $site)
            @php
                $cats = publisherSitesCategoryList($site);
                $isArchived = method_exists($site, 'isArchived') ? $site->isArchived() : false;
            @endphp
            <tr class="main-row" data-id="{{ $site->id }}">
                <td data-label="#">{{ $sites->firstItem() + $index }}</td>
                <td data-label="Site">
                    {{ $site->site_name }}
                    @if(($site->pending_claims_count ?? 0) > 0)
                        <div><span class="badge bg-warning text-dark">Claim pending</span></div>
                    @endif
                </td>
                <td data-label="URL">{{ $site->site_url }}</td>
                <td data-label="Category">
                    @forelse($cats as $cat)
                        <span class="category-chip">{{ $cat }}</span>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </td>
                <td data-label="DA">{{ $site->da }}</td>
                <td data-label="DR">{{ $site->dr }}</td>
                <td data-label="Traffic">{{ number_format($site->traffic, 0, '.', ',') }}</td>
                <td data-label="Market">
                    <div class="d-flex flex-column align-items-md-center gap-1">
                        <span class="country-flag" aria-hidden="true" style="font-size:24px;">
                            @php
                                $siteCountries = is_array($site->countries) && count($site->countries)
                                    ? $site->countries
                                    : array_filter([$site->country]);
                            @endphp
                            @foreach($siteCountries as $code)
                                {!! publisherSitesCountryFlag($code) !!}
                            @endforeach
                        </span>
                        <span class="language-name" style="font-size:12px;color:#666;">
                            @php
                                $siteLanguages = is_array($site->languages) && count($site->languages)
                                    ? $site->languages
                                    : array_filter([$site->language]);
                            @endphp
                            {{ collect($siteLanguages)->map(fn ($c) => fullLanguage($c))->implode(', ') }}
                        </span>
                    </div>
                </td>
                <td data-label="Status">
                    @if($isArchived)
                        <span class="badge bg-dark status-badge" title="Archived — hidden from catalog">
                            <i class="fa fa-box-archive me-1"></i>Archived
                        </span>
                    @elseif($site->verified && $site->active)
                        <span class="badge bg-success status-badge" title="Verified and live in catalog">
                            <i class="fa-solid fa-circle-check me-1"></i>Verified · live
                        </span>
                    @elseif($site->verified && ! $site->active)
                        <span class="badge bg-secondary status-badge" title="Verified but inactive">
                            <i class="fa-solid fa-circle-pause me-1"></i>Verified · inactive
                        </span>
                    @elseif($site->active)
                        <span class="badge bg-info status-badge" title="Active in catalog but not verified">
                            <i class="fa-solid fa-circle-play me-1"></i>Active
                        </span>
                    @else
                        <span class="badge bg-secondary status-badge" title="Pending admin review">
                            <i class="fa-regular fa-clock me-1"></i>Pending
                        </span>
                        <div class="pending-meta">
                            Submitted {{ $site->created_at?->diffForHumans() }}
                            <div>Usually reviewed within 24–48 hours</div>
                            @if($site->updated_at && $site->updated_at->ne($site->created_at))
                                <div>Updated {{ $site->updated_at->diffForHumans() }}</div>
                            @endif
                        </div>
                    @endif
                </td>
                <td data-label="Price">
                    €{{ number_format($site->price, 2) }}
                    @if($site->isFeatured())
                        <div><span class="badge bg-warning text-dark mt-1">Featured</span></div>
                    @endif
                    @if($site->hasActiveCustomDiscount())
                        <div><span class="badge bg-danger mt-1">−{{ rtrim(rtrim(number_format((float) $site->custom_discount_percent, 1), '0'), '.') }}% offer</span></div>
                    @endif
                    @if($site->joinsBulkDiscount())
                        <div><span class="badge bg-success mt-1">Bulk −{{ rtrim(rtrim(number_format((float) $site->bulk_discount_percent, 1), '0'), '.') }}%</span></div>
                    @endif
                </td>
                <td data-label="Actions">
                    <div class="d-flex flex-wrap gap-1 justify-content-center">
                        <button type="button" class="btn btn-sm btn-outline-primary action-view" data-id="{{ $site->id }}">
                            <i class="fa fa-eye me-1"></i><span class="btn-text">View</span>
                        </button>

                        @unless($isArchived)
                            <button type="button" class="btn btn-sm btn-primary btn-edit" data-id="{{ $site->id }}">Edit</button>
                        @endunless

                        @if(($site->active || $site->verified) && ! $isArchived)
                            <button type="button" class="btn btn-sm btn-warning btn-feature-site"
                                    data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">
                                <i class="fa fa-bolt"></i> Feature
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success btn-discount-site"
                                    data-id="{{ $site->id }}"
                                    data-name="{{ $site->site_name }}"
                                    data-percent="{{ $site->custom_discount_percent }}"
                                    data-ends="{{ optional($site->custom_discount_ends_at)?->toIso8601String() }}">
                                <i class="fa fa-percent"></i> Discount
                            </button>
                            @if($site->hasActiveCustomDiscount())
                                <button type="button" class="btn btn-sm btn-outline-danger btn-discount-clear" data-id="{{ $site->id }}">Clear</button>
                            @endif
                            @if($site->joinsBulkDiscount())
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-bulk-leave" data-id="{{ $site->id }}">Leave bulk</button>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-success btn-bulk-join" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">Join bulk</button>
                            @endif
                        @endif

                        @if(! $site->verified && ! $site->active && ! $isArchived)
                            <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" class="delete-form d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="button" class="btn btn-sm btn-danger btn-delete">Delete</button>
                            </form>
                        @endif

                        @if(($site->verified || $site->active) && ! $isArchived)
                            <button type="button" class="btn btn-sm btn-outline-dark btn-archive-site" data-id="{{ $site->id }}" data-name="{{ $site->site_name }}">
                                Archive
                            </button>
                        @endif

                        @if($isArchived)
                            <button type="button" class="btn btn-sm btn-outline-primary btn-unarchive-site" data-id="{{ $site->id }}">
                                Restore
                            </button>
                        @endif
                    </div>
                </td>
            </tr>
            <tr class="expand-row" id="expand-{{ $site->id }}">
                <td colspan="11">
                    <div class="expand-box">
                        <div class="detail-line"><strong>Example URL:</strong> <a href="{{ $site->example_url }}" target="_blank" rel="noopener">{{ $site->example_url }}</a></div>
                        <div class="detail-line"><strong>Publication:</strong> {{ match($site->publication_time) { '6months' => '6 Months', '1year' => '1 Year', 'permanent' => 'Permanent', default => ucfirst((string) $site->publication_time) } }}</div>
                        <div class="detail-line"><strong>Link Type:</strong> {{ ucfirst((string) $site->link_type) }}</div>
                        <div class="detail-line">
                            <strong>Turnaround:</strong>
                            <span class="turnaround-badge">{{ match($site->turnaround_time) {
                                '24h' => '24 Hours', '48h' => '48 Hours', '3days' => '3 Days', '5days' => '5 Days', '7days' => '7 Days', default => '3 Days'
                            } }}</span>
                        </div>
                        <div class="detail-line">
                            <strong>Tags:</strong>
                            @if($site->sponsored)<span class="tag-badge">Sponsored</span>@endif
                            @if($site->partner_material)<span class="tag-badge">Partner Material</span>@endif
                            @if($site->as_you_prefer)<span class="tag-badge">As You Prefer</span>@endif
                            @if(! $site->sponsored && ! $site->partner_material && ! $site->as_you_prefer)
                                <span class="text-muted">No tags</span>
                            @endif
                        </div>
                        @if($site->sensitive_prices)
                            <div class="detail-line">
                                <strong>Sensitive Topics:</strong>
                                @foreach((array) $site->sensitive_prices as $key => $value)
                                    <span class="sensitive-badge">{{ ucfirst($key) }}: €{{ number_format((float) $value, 2) }}</span>
                                @endforeach
                            </div>
                        @endif
                        <div class="desc-box">
                            <strong>Description:</strong>
                            <div>{!! $site->description !!}</div>
                        </div>
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

@if($sites->hasPages())
    <div class="sites-ajax-pagination mt-3">
        {{ $sites->links() }}
    </div>
@endif
@else
    <div class="dash-panel text-center py-4">
        <p class="mb-2 fw-semibold">No websites match this filter</p>
        <p class="text-muted small mb-3">Try another status filter or add a new site.</p>
        <button type="button" class="btn btn-primary btn-sm" id="emptyAddSiteCta"><i class="fa fa-plus"></i> Add New Website</button>
    </div>
@endif
