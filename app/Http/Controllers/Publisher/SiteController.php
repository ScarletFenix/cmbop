<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\EmailNotificationService;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SiteController extends Controller
{
    public function index()
    {
        // Europe + major North America markets
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter (Category::catalogPickerNames).
        $categories = Category::catalogPickerNames();
        $languages = Language::marketplace()
            ->with(['countries' => fn ($q) => $q->marketplace()->select('countries.id', 'countries.code', 'countries.name')])
            ->orderBy('name')
            ->get();

        // Map language code → related countries (e.g. German → DE, AT, CH)
        $languageCountryMap = [];
        foreach ($languages as $language) {
            $languageCountryMap[$language->code] = $language->countries
                ->map(fn ($c) => ['code' => strtolower($c->code), 'name' => $c->name])
                ->values()
                ->all();
        }

        // English sites can target every English-speaking market we list:
        // English regions + Chinese markets + Gulf + any pivot EN countries.
        $languageCountryMap['en'] = $this->englishMarketplaceCountries();

        $openBulkRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])
            ->latest()
            ->first();

        $awaitingDetailsCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        $detailsCompleteCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)
            ->count();

        return view('publisher.websites', compact(
            'countries',
            'categories',
            'languages',
            'languageCountryMap',
            'openBulkRequest',
            'awaitingDetailsCount',
            'detailsCompleteCount'
        ));
    }

    /**
     * Countries where publishers may list English-language sites.
     *
     * @return list<array{code: string, name: string}>
     */
    private function englishMarketplaceCountries(): array
    {
        $codes = array_values(array_unique(array_merge(
            config('markets.english_region_country_codes', []),
            config('markets.chinese_country_codes', []),
            config('markets.gulf_country_codes', []),
            Language::where('code', 'en')
                ->first()
                ?->countries()
                ->marketplace()
                ->pluck('code')
                ->all() ?? []
        )));

        return Country::marketplace()
            ->whereIn('code', $codes)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($c) => ['code' => strtolower((string) $c->code), 'name' => $c->name])
            ->values()
            ->all();
    }

    public function getCountryLanguages($countryCode)
    {
        $country = Country::where('code', $countryCode)->first();

        if (! $country) {
            return response()->json([]);
        }

        $languages = DB::table('country_language')
            ->join('languages', 'country_language.language_id', '=', 'languages.id')
            ->where('country_language.country_id', $country->id)
            ->select('languages.code', 'languages.name')
            ->get();

        return response()->json($languages);
    }

    public function store(Request $request)
    {
        // Normalize URLs before validation (publishers often omit https://)
        $siteUrl = $this->normalizeHttpUrl((string) $request->input('siteUrl', ''));
        $exampleUrl = $this->normalizeHttpUrl((string) $request->input('exampleUrl', ''));
        $request->merge([
            'siteUrl' => $siteUrl,
            'exampleUrl' => $exampleUrl,
        ]);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! $host) {
            return back()->withErrors(['siteUrl' => 'Invalid URL'])->withInput();
        }

        $domain = preg_replace('/^www\./', '', strtolower($host));

        // Handle categories - get as array from multi-select
        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        // Pipe-join avoids breaking names that contain commas (e.g. "Marketing, PR & Advertising")
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : (string) $request->category;
        $categoriesArray = ! empty($categories) ? $categories : null;

        // Single country + single language per website (manual entry — never auto-overwritten)
        $countryCodes = array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1);

        $request->merge([
            'country' => $countryCodes[0] ?? null,
            'language' => $languageCodes[0] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
        ]);

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        if ($allowedCountries === [] || $allowedLanguages === []) {
            Log::error('Publisher site store blocked: empty marketplace country/language lists', [
                'user_id' => auth()->id(),
                'countries' => count($allowedCountries),
                'languages' => count($allowedLanguages),
            ]);

            return redirect()->back()
                ->withErrors([
                    'country' => 'Marketplace countries or languages are not configured. Please contact support — your listing was not saved.',
                ])
                ->withInput();
        }

        $validator = Validator::make($request->all(), [
            'siteName' => 'required|string|max:255',
            'siteUrl' => 'required|url|max:255',
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($domain) {
            if (Site::where('publisher_id', auth()->id())->where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'You have already added this website.');
            }
        });

        $validator->after(function ($validator) use ($domain) {
            if (Site::where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'This website domain is already registered by another publisher. If you own it, open the Catalog, find that site, and use Claim so we can verify ownership and transfer the listing.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize((string) $request->siteDescription);

        $site = null;

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, &$site) {
                $site = new Site;

                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                // Manual publisher metrics — never auto-fetched/overwritten.
                // applyMarketplaceListing skips columns missing on older Hostinger DBs
                // and fits legacy category VARCHAR(50) when multi-category strings are long.
                $site->applyMarketplaceListing([
                    'publisher_id' => auth()->id(),
                    'publisher_accepted_at' => now(),
                    'assigned_by_user_id' => null,
                    'site_name' => $request->siteName,
                    'site_url' => $request->siteUrl,
                    'domain' => $domain,
                    'example_url' => $request->exampleUrl,
                    'da' => (int) $request->da,
                    'dr' => (int) $request->dr,
                    'traffic' => (int) $request->traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->price,
                    'turnaround_time' => $request->turnaround_time,
                    'publication_time' => $request->publicationTime,
                    'link_type' => $request->link_type,
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                ]);

                $this->applySiteTag($site, $request);

                $site->save();
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site store failed', [
                'user_id' => auth()->id(),
                'domain' => $domain,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $hint = 'We could not save this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'Data too long')
                || str_contains($e->getMessage(), 'onboarding_status')) {
                $hint = 'We could not save this website because the database is missing a recent update. Please contact support (or run the sites column migration SQL).';
            }

            return redirect()->back()
                ->withErrors(['siteUrl' => $hint])
                ->withInput();
        }

        // Homepage screenshot only (compress + WebP). Metrics stay manual.
        if ($site && config('site_enrichment.enabled', true)) {
            try {
                CaptureSiteScreenshotJob::dispatch($site->id, 'publisher_create');
            } catch (\Throwable $e) {
                Log::warning('Failed to queue site screenshot job', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($site) {
            try {
                app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'create');
            } catch (\Throwable $e) {
                Log::error('Failed to notify admins of new publisher site: '.$e->getMessage(), [
                    'site_id' => $site->id,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours. A homepage screenshot is being generated automatically.');
    }

    public function ajax(Request $request)
    {
        try {
            $query = $request->get('query');
            $status = strtolower((string) $request->get('status', 'active'));
            if (! in_array($status, ['pending', 'active', 'invites'], true)) {
                $status = 'active';
            }
            $page = max(1, (int) $request->get('page', 1));

            $base = Site::where('publisher_id', auth()->id());
            $acceptedBase = (clone $base)->acceptedByPublisher();

            $openBulkRequest = BulkSiteRequest::query()
                ->where('publisher_id', auth()->id())
                ->whereNotIn('status', [
                    BulkSiteRequest::STATUS_COMPLETED,
                    BulkSiteRequest::STATUS_CANCELLED,
                ])
                ->latest()
                ->first();

            $waitingItemsQuery = BulkSiteRequestItem::query()
                ->whereNull('site_id')
                ->whereHas('bulkRequest', function ($q) {
                    $q->where('publisher_id', auth()->id())
                        ->whereNotIn('status', [
                            BulkSiteRequest::STATUS_COMPLETED,
                            BulkSiteRequest::STATUS_CANCELLED,
                        ]);
                });

            $waitingItemsCount = (clone $waitingItemsQuery)->count();
            $sitePendingCount = (clone $acceptedBase)->where('active', 0)->where('verified', 0)->count();
            $pendingCount = $sitePendingCount + $waitingItemsCount;
            $inviteCount = (clone $base)->pendingPublisherAcceptance()->count();

            $activeQuery = (clone $acceptedBase)->where(function ($q) {
                $q->where('active', 1)->orWhere('verified', 1);
            });
            $activeCount = (clone $activeQuery)->count();
            $activeIds = (clone $activeQuery)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $bulkWaitingItems = collect();
            if ($status === 'pending' && $page === 1) {
                $bulkWaitingItems = (clone $waitingItemsQuery)
                    ->when($query, function ($q) use ($query) {
                        $q->where(function ($sub) use ($query) {
                            $sub->where('site_url', 'like', "%{$query}%")
                                ->orWhere('domain', 'like', "%{$query}%");
                        });
                    })
                    ->orderBy('id')
                    ->get();
            }

            $sites = ($status === 'invites'
                    ? (clone $base)->pendingPublisherAcceptance()
                    : (clone $acceptedBase))
                ->when($status === 'pending', function ($q) {
                    $q->where('active', 0)->where('verified', 0);
                })
                ->when($status === 'active', function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('active', 1)->orWhere('verified', 1);
                    });
                })
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($sub) use ($query) {
                        $sub->where('site_name', 'like', "%{$query}%")
                            ->orWhere('site_url', 'like', "%{$query}%")
                            ->orWhere('domain', 'like', "%{$query}%");
                    });
                })
                ->latest()
                ->paginate(20)
                ->appends([
                    'status' => $status,
                    'query' => $query,
                ]);

            return view('publisher.sites.partials.table', compact(
                'sites',
                'pendingCount',
                'activeCount',
                'inviteCount',
                'activeIds',
                'status',
                'bulkWaitingItems',
                'openBulkRequest',
                'waitingItemsCount'
            ))->render();
        } catch (\Throwable $e) {
            Log::error('Publisher sites ajax failed: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'exception' => $e,
            ]);

            return response(
                '<div class="alert alert-danger text-center mb-0">Could not load your sites. Please refresh and try again.</div>',
                500
            );
        }
    }

    public function acceptAssignment(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if (! $site->isPendingPublisherAcceptance()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is not waiting for acceptance.',
                ], 422);
            }

            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This site is not waiting for acceptance.');
        }

        $site->publisher_accepted_at = now();
        $site->save();

        try {
            ActivityLogger::log(
                'site.assignment_accepted',
                (auth()->user()->name ?? 'Publisher').' accepted staff-assigned site "'.$site->site_name.'"',
                $site,
                [
                    'publisher_id' => auth()->id(),
                    'assigned_by_user_id' => $site->assigned_by_user_id,
                    'domain' => $site->domain,
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log publisher site acceptance: '.$e->getMessage());
        }

        try {
            app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'accept');
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins after publisher accepted staff-assigned site: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site accepted. It now appears in My Sites.',
                'site_id' => $site->id,
            ]);
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', 'Site accepted. It now appears in My Sites (Pending) until staff activate it.');
    }

    public function rejectAssignment(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if (! $site->isPendingPublisherAcceptance()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is not waiting for acceptance.',
                ], 422);
            }

            return redirect()
                ->route('publisher.websites', ['status' => 'invites'])
                ->with('error', 'This site is not waiting for acceptance.');
        }

        $siteId = $site->id;
        $domain = $site->domain ?: $site->site_name;
        $site->delete();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site invitation declined.',
                'site_id' => $siteId,
            ]);
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'invites'])
            ->with('success', 'Declined '.$domain.'. The listing was removed.');
    }

    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isPendingPublisherAcceptance()) {
            return redirect()->back()->withErrors([
                'site' => 'Accept this staff-added site before editing it.',
            ]);
        }

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl((string) $request->input('exampleUrl')),
            ]);
        }

        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : $site->category;
        $categoriesArray = ! empty($categories) ? $categories : null;

        $countryCodes = array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1);

        $request->merge([
            'country' => $countryCodes[0] ?? null,
            'language' => $languageCodes[0] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
        ]);

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        if ($allowedCountries === [] || $allowedLanguages === []) {
            Log::error('Publisher site update blocked: empty marketplace country/language lists', [
                'user_id' => auth()->id(),
                'site_id' => $site->id,
                'countries' => count($allowedCountries),
                'languages' => count($allowedLanguages),
            ]);

            return redirect()->back()
                ->withErrors([
                    'country' => 'Marketplace countries or languages are not configured. Please contact support — your changes were not saved.',
                ])
                ->withInput();
        }

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($request, $site) {
            $newDomain = null;
            if ($request->filled('siteUrl')) {
                $url = $this->normalizeHttpUrl((string) $request->siteUrl);
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    $newDomain = preg_replace('/^www\./', '', strtolower($host));
                }
            }

            if ($newDomain && $newDomain !== $site->domain) {
                $existingSite = Site::where('domain', $newDomain)
                    ->where('id', '!=', $site->id)
                    ->exists();
                if ($existingSite) {
                    $validator->errors()->add('siteUrl', 'This website domain is already registered in our system by another publisher.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize((string) $request->siteDescription);

        try {
            DB::transaction(function () use ($site, $request, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes) {
                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                $listing = [
                    'example_url' => $request->exampleUrl,
                    'da' => (int) $request->da,
                    'dr' => (int) $request->dr,
                    'traffic' => (int) $request->traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->price,
                    'turnaround_time' => $request->turnaround_time,
                    'publication_time' => $request->publicationTime,
                    'link_type' => $request->link_type,
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                ];

                // Bulk drafts stay with the publisher until Review & submit.
                $keepAsBulkDraft = $site->awaitsPublisherDetails() || $site->hasDetailsComplete();

                $site->applyMarketplaceListing($listing);

                $this->applySiteTag($site, $request);

                $site->save();

                // Move awaiting_details → details_complete (not admin queue yet).
                if ($keepAsBulkDraft && ! $site->markDetailsComplete()) {
                    throw new \RuntimeException('onboarding_status details_complete rejected by database');
                }
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site update failed', [
                'site_id' => $site->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $hint = 'We could not update this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'Data too long')
                || str_contains($e->getMessage(), 'onboarding_status')) {
                $hint = 'We could not update this website because the database is missing a recent update. Please contact support (or run the sites column migration SQL).';
            }

            return redirect()->back()
                ->withErrors(['siteUrl' => $hint])
                ->withInput();
        }

        $site->refresh();
        if ($site->bulk_site_request_id) {
            $site->bulkSiteRequest?->refreshProgressStatus();
        }

        // Pre-submit bulk drafts: no admin notify until Review & submit.
        if ($site->hasDetailsComplete() || $site->awaitsPublisherDetails()) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('success', '“'.$site->site_name.'” saved. Review your sites, then submit for admin review.');
        }

        try {
            app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'update');
        } catch (\Throwable $e) {
            Log::error('Failed to notify admins of publisher site update: '.$e->getMessage(), [
                'site_id' => $site->id,
            ]);
        }

        return redirect()->back()->with('success', 'Site updated successfully! It will be reviewed again.');
    }

    public function destroy($id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isPendingPublisherAcceptance()) {
            $site->delete();

            return redirect()
                ->route('publisher.websites', ['status' => 'invites'])
                ->with('success', 'Site invitation declined.');
        }

        if ($site->verified || $site->active) {
            return redirect()->back()->with('error', 'You cannot delete an active or verified site.');
        }

        $site->delete();

        return redirect()->back()->with('success', 'Site deleted successfully!');
    }

    /**
     * Parse country/language codes from array, CSV, or pipe-separated string.
     */
    private function parseCodeList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[|,]/', (string) $value) ?: [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = strtolower(trim((string) $part));
            if ($code !== '' && preg_match('/^[a-z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Apply a single site tag from radio `site_tag`, with checkbox fallback.
     */
    private function applySiteTag(Site $site, Request $request): void
    {
        $tag = $request->input('site_tag');

        if ($tag === null) {
            // Legacy checkbox posts / bulk import paths
            $site->sponsored = $request->boolean('sponsored') || $request->has('sponsored');
            $site->partner_material = $request->boolean('partner_material') || $request->has('partner_material');
            $site->as_you_prefer = $request->boolean('as_you_prefer') || $request->has('as_you_prefer');

            return;
        }

        $site->sponsored = $tag === 'sponsored';
        $site->partner_material = $tag === 'partner_material';
        $site->as_you_prefer = $tag === 'as_you_prefer';
    }

    /**
     * Ensure URLs validate even when publishers omit the scheme.
     */
    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /**
     * Parse category names from array, JSON, CSV, or pipe-separated string.
     * Prefer `|` as the delimiter so names containing commas stay intact.
     */
    private function parseCategoryList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $parts = $decoded;
            } elseif (str_contains($value, '|')) {
                $parts = explode('|', $value);
            } else {
                // If the whole string is a known category (may contain commas), keep it intact.
                $known = Category::query()->where('name', $value)->exists();
                $parts = $known ? [$value] : (preg_split('/,/', $value) ?: []);
            }
        } else {
            $parts = [];
        }

        $categories = [];
        foreach ($parts as $part) {
            $name = trim((string) $part);
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique($categories));
    }
}
