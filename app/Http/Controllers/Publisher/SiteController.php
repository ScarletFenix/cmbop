<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Mail\NewSiteNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Support\NormalizesHttpUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SiteController extends Controller
{
    use NormalizesHttpUrls;

    public function index()
    {
        // Europe + major North America markets
        $countries = Country::marketplace()->orderBy('name')->get();
        $categories = Category::orderBy('group')->orderBy('name')->get();
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

        return view('publisher.websites', compact('countries', 'categories', 'languages', 'languageCountryMap'));
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

        $validator = Validator::make($request->all(), [
            'siteName' => 'required|string|max:255',
            'siteUrl' => 'required|url|max:255',
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
            'sensitive.crypto' => 'nullable|boolean',
            'sensitive.trading' => 'nullable|boolean',
            'sensitive.CBD' => 'nullable|boolean',
            'sensitive.forex' => 'nullable|boolean',
            'price_sensitive.crypto' => 'nullable|required_with:sensitive.crypto|numeric|min:0',
            'price_sensitive.trading' => 'nullable|required_with:sensitive.trading|numeric|min:0',
            'price_sensitive.CBD' => 'nullable|required_with:sensitive.CBD|numeric|min:0',
            'price_sensitive.forex' => 'nullable|required_with:sensitive.forex|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($domain) {
            if (Site::where('publisher_id', auth()->id())->where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'You have already added this website.');

                return;
            }

            if (Site::where('domain', $domain)->where('publisher_id', '!=', auth()->id())->exists()) {
                $validator->errors()->add('siteUrl', 'This website domain is already registered by another publisher. If you own it, use “Claim a website” on this page so we can verify the listing name and transfer ownership.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $site = null;

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, &$site) {
                $site = new Site;

                $sensitivePrices = $this->collectSensitivePrices($request);

                // Manual publisher metrics — never auto-fetched/overwritten.
                // applyMarketplaceListing skips columns missing on older Hostinger DBs
                // and fits legacy category VARCHAR(50) when multi-category strings are long.
                $site->applyMarketplaceListing([
                    'publisher_id' => auth()->id(),
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
            ]);

            $hint = 'We could not save this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column') || str_contains($e->getMessage(), 'Data too long')) {
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
                $admins = User::where('active_role_id', function ($query) {
                    $query->select('id')
                        ->from('roles')
                        ->where('name', 'admin')
                        ->limit(1);
                })->get();

                if ($admins->count() > 0) {
                    foreach ($admins as $admin) {
                        Mail::to($admin->email)->send(new NewSiteNotification($site));
                    }
                } else {
                    $defaultAdminEmail = config('mail.admin_email');
                    if ($defaultAdminEmail) {
                        Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site));
                    }
                }
            } catch (\Exception $e) {
                Log::error('Failed to send email notification: '.$e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours. A homepage screenshot is being generated automatically.');
    }

    public function ajax(Request $request)
    {
        $query = trim((string) $request->get('query', ''));
        $status = trim((string) $request->get('status', 'all'));

        $sites = Site::where('publisher_id', auth()->id())
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('site_name', 'like', "%{$query}%")
                        ->orWhere('site_url', 'like', "%{$query}%")
                        ->orWhere('domain', 'like', "%{$query}%");
                });
            })
            ->when($status !== '' && $status !== 'all', function ($q) use ($status) {
                match ($status) {
                    'pending' => $q->where('verified', false)->where('active', false)->notArchived(),
                    'verified' => $q->where('verified', true)->notArchived(),
                    'active' => $q->where('active', true)->notArchived(),
                    'featured' => Schema::hasColumn('sites', 'featured_until')
                        ? $q->notArchived()->whereNotNull('featured_until')->where('featured_until', '>', now())
                        : $q->whereRaw('1 = 0'),
                    'archived' => $q->archived(),
                    default => $q->notArchived(),
                };
            }, function ($q) {
                $q->notArchived();
            })
            ->when(Schema::hasTable('site_claims'), function ($q) {
                $q->withCount([
                    'claims as pending_claims_count' => fn ($c) => $c->where('status', 'pending'),
                ]);
            })
            ->latest()
            ->paginate(20)
            ->appends([
                'query' => $query,
                'status' => $status,
            ]);

        $pendingOutgoingClaims = Schema::hasTable('site_claims')
            ? SiteClaim::query()
                ->where('claimer_id', auth()->id())
                ->where('status', 'pending')
                ->with('site:id,site_name,domain')
                ->latest()
                ->limit(10)
                ->get()
            : collect();

        return view('publisher.sites.partials.table', compact('sites', 'pendingOutgoingClaims', 'status'))->render();
    }

    /**
     * Lean JSON payload for the edit form (avoids stuffing full models into DOM attributes).
     */
    public function editData(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        $categories = is_array($site->categories) && count($site->categories)
            ? array_values($site->categories)
            : array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $site->category) ?: [])));

        return response()->json([
            'success' => true,
            'site' => [
                'id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'example_url' => $site->example_url,
                'da' => (int) $site->da,
                'dr' => (int) $site->dr,
                'traffic' => (int) $site->traffic,
                'country' => $site->country,
                'countries' => $site->countries,
                'language' => $site->language,
                'languages' => $site->languages,
                'category' => $site->category,
                'categories' => $categories,
                'price' => (float) $site->price,
                'turnaround_time' => $site->turnaround_time,
                'publication_time' => $site->publication_time,
                'link_type' => $site->link_type,
                'description' => $site->description,
                'sponsored' => (bool) $site->sponsored,
                'partner_material' => (bool) $site->partner_material,
                'as_you_prefer' => (bool) $site->as_you_prefer,
                'sensitive_prices' => $site->sensitive_prices ?: new \stdClass,
                'verified' => (bool) $site->verified,
                'active' => (bool) $site->active,
                'is_live' => (bool) ($site->verified || $site->active),
                'is_archived' => $site->isArchived(),
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isArchived()) {
            return redirect()->back()->with('error', 'Archived sites cannot be edited. Restore the site first.');
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

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
            'sensitive.crypto' => 'nullable|boolean',
            'sensitive.trading' => 'nullable|boolean',
            'sensitive.CBD' => 'nullable|boolean',
            'sensitive.forex' => 'nullable|boolean',
            'price_sensitive.crypto' => 'nullable|required_with:sensitive.crypto|numeric|min:0',
            'price_sensitive.trading' => 'nullable|required_with:sensitive.trading|numeric|min:0',
            'price_sensitive.CBD' => 'nullable|required_with:sensitive.CBD|numeric|min:0',
            'price_sensitive.forex' => 'nullable|required_with:sensitive.forex|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('editing_site_id', $site->id);
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $needsRereview = $this->updateRequiresRereview($site, $countryCodes[0] ?? null, $languageCodes[0] ?? null, $categoriesArray ?? []);
        $wasLive = $site->verified || $site->active;

        try {
            DB::transaction(function () use ($site, $request, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, $needsRereview) {
                $sensitivePrices = $this->collectSensitivePrices($request);

                $payload = [
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
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                ];

                if ($needsRereview) {
                    $payload['verified'] = false;
                    $payload['active'] = false;
                }

                $site->applyMarketplaceListing($payload);
                $this->applySiteTag($site, $request);
                $site->save();
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site update failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withErrors(['siteUrl' => 'We could not update this website. Please check your details and try again.'])
                ->withInput()
                ->with('editing_site_id', $site->id);
        }

        if ($needsRereview) {
            try {
                $admins = User::where('active_role_id', function ($query) {
                    $query->select('id')
                        ->from('roles')
                        ->where('name', 'admin')
                        ->limit(1);
                })->get();

                if ($admins->count() > 0) {
                    foreach ($admins as $admin) {
                        Mail::to($admin->email)->send(new NewSiteNotification($site, 'update'));
                    }
                } else {
                    $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                    Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site, 'update'));
                }
            } catch (\Exception $e) {
                Log::error('Failed to send email notification: '.$e->getMessage());
            }
        }

        if ($needsRereview && $wasLive) {
            return redirect()->back()->with('success', 'Site updated. Market/niche changes require re-review — it is offline until an admin approves it again.');
        }

        if ($needsRereview) {
            return redirect()->back()->with('success', 'Site updated and queued for review.');
        }

        return redirect()->back()->with('success', 'Site updated successfully.');
    }

    public function destroy($id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->verified || $site->active) {
            return redirect()->back()->with('error', 'You cannot delete an active or verified site. Archive it instead.');
        }

        if ($site->isArchived()) {
            return redirect()->back()->with('error', 'Archived sites cannot be deleted from here.');
        }

        $site->delete();

        return redirect()->back()->with('success', 'Site deleted successfully!');
    }

    public function archive(int $id)
    {
        if (! Schema::hasColumn('sites', 'archived_at')) {
            return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
        }

        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Site is already archived.'], 422);
        }

        if (! $site->verified && ! $site->active) {
            return response()->json([
                'success' => false,
                'message' => 'Pending sites cannot be archived. Delete the listing instead.',
            ], 422);
        }

        // Hide via archived_at only — keep active/verified so restore does not force a site live.
        $site->archived_at = now();
        $site->save();

        return response()->json([
            'success' => true,
            'message' => 'Site archived and hidden from the catalog.',
        ]);
    }

    public function unarchive(int $id)
    {
        if (! Schema::hasColumn('sites', 'archived_at')) {
            return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
        }

        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if (! $site->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Site is not archived.'], 422);
        }

        $site->archived_at = null;
        $site->save();

        return response()->json([
            'success' => true,
            'message' => $site->active
                ? 'Site restored to the catalog.'
                : 'Site restored. It remains inactive until it is active again.',
        ]);
    }

    /**
     * Material market/niche edits require admin re-review.
     *
     * @param  list<string>  $newCategories
     */
    private function updateRequiresRereview(Site $site, ?string $newCountry, ?string $newLanguage, array $newCategories): bool
    {
        $oldCountry = strtolower((string) $site->country);
        $oldLanguage = strtolower((string) $site->language);
        if (strtolower((string) $newCountry) !== $oldCountry) {
            return true;
        }
        if (strtolower((string) $newLanguage) !== $oldLanguage) {
            return true;
        }

        $oldCategories = is_array($site->categories) && count($site->categories)
            ? array_values($site->categories)
            : array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $site->category) ?: [])));

        $normalize = static function (array $cats): array {
            $out = array_map(static fn ($c) => mb_strtolower(trim((string) $c)), $cats);
            sort($out);

            return array_values($out);
        };

        return $normalize($oldCategories) !== $normalize($newCategories);
    }

    /**
     * @return array<string, float>
     */
    private function collectSensitivePrices(Request $request): array
    {
        $sensitivePrices = [];
        foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
            if (! $request->input("sensitive.$topic")) {
                continue;
            }

            $price = $request->input("price_sensitive.$topic");
            if ($price === null || $price === '') {
                continue;
            }

            $sensitivePrices[$topic] = (float) $price;
        }

        return $sensitivePrices;
    }

    /**
     * Download a CSV template for agency bulk site import (150+ sites).
     */
    public function bulkTemplate()
    {
        $headers = [
            'site_name',
            'site_url',
            'example_url',
            'da',
            'dr',
            'traffic',
            'country',
            'language',
            'categories',
            'price',
            'turnaround_time',
            'publication_time',
            'link_type',
            'description',
            'sponsored',
            'partner_material',
            'as_you_prefer',
            'price_crypto',
            'price_trading',
            'price_CBD',
            'price_forex',
        ];

        $example = [
            'My Agency Blog',
            'https://example-agency-blog.com',
            'https://example-agency-blog.com/sample-post',
            '45',
            '40',
            '15000',
            'de',
            'de',
            'Business & Finance|Technology',
            '120',
            '3days',
            'permanent',
            'dofollow',
            'High-quality editorial site covering business and technology topics for professional audiences.',
            '0',
            '1',
            '0',
            '',
            '',
            '',
            '',
        ];

        $callback = function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        };

        return response()->streamDownload($callback, 'agency-sites-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Bulk-import websites from CSV for agencies that manage many domains.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
            'dry_run' => 'nullable|boolean',
        ], [
            'csv_file.required' => 'Please upload a CSV file.',
            'csv_file.mimes' => 'Upload a .csv file.',
        ]);

        $dryRun = $request->boolean('dry_run');
        $maxRows = 200;
        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', 'Could not read the uploaded file.');
        }

        // Skip UTF-8 BOM if present
        $firstBytes = fread($handle, 3);
        if ($firstBytes !== chr(0xEF).chr(0xBB).chr(0xBF)) {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if (! $headerRow) {
            fclose($handle);

            return back()->with('error', 'CSV is empty.');
        }

        $headers = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
        }, $headerRow);

        $requiredHeaders = [
            'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
            'categories', 'price', 'turnaround_time',
            'publication_time', 'link_type', 'description',
        ];

        // Accept either countries/languages (new) or country/language (legacy single)
        $hasCountries = in_array('countries', $headers, true) || in_array('country', $headers, true);
        $hasLanguages = in_array('languages', $headers, true) || in_array('language', $headers, true);
        if (! $hasCountries || ! $hasLanguages) {
            fclose($handle);

            return back()->with('error', 'CSV must include countries (or country) and languages (or language) columns. Download the template and try again.');
        }

        foreach ($requiredHeaders as $required) {
            if (! in_array($required, $headers, true)) {
                fclose($handle);

                return back()->with('error', "CSV is missing required column: {$required}. Download the template and try again.");
            }
        }

        $validCategoryNames = Category::pluck('name')->map(fn ($n) => strtolower($n))->all();
        $publisherId = auth()->id();

        $created = 0;
        $wouldCreate = 0;
        $failed = [];
        $seenDomainsInFile = [];
        $rowNumber = 1; // header is row 1
        $processed = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip completely empty rows
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            if (($created + $wouldCreate + count($failed)) >= $maxRows) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => '',
                    'errors' => ["Maximum {$maxRows} rows per upload. Remaining rows were skipped."],
                ];
                break;
            }

            // Pad/truncate to header length
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            if ($data === false) {
                $failed[] = ['row' => $rowNumber, 'site' => '', 'errors' => ['Could not parse row.']];

                continue;
            }

            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);
            $processed++;

            // Skip the sample template row if left unchanged
            if (($data['site_url'] ?? '') === 'https://example-agency-blog.com') {
                continue;
            }

            $parsed = $this->normalizeBulkRow($data, $validCategoryNames);

            if (! empty($parsed['errors'])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? ($data['site_name'] ?? ''),
                    'errors' => $parsed['errors'],
                ];

                continue;
            }

            $domain = $parsed['domain'];

            if (isset($seenDomainsInFile[$domain])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ["Duplicate domain in this file (also on row {$seenDomainsInFile[$domain]})."],
                ];

                continue;
            }
            $seenDomainsInFile[$domain] = $rowNumber;

            if (Site::where('domain', $domain)->exists()) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ['This domain is already registered in the system.'],
                ];

                continue;
            }

            if ($dryRun) {
                $wouldCreate++;

                continue;
            }

            try {
                DB::transaction(function () use ($parsed, $publisherId) {
                    $site = new Site;
                    $site->applyMarketplaceListing([
                        'publisher_id' => $publisherId,
                        'site_name' => $parsed['site_name'],
                        'site_url' => $parsed['site_url'],
                        'domain' => $parsed['domain'],
                        'example_url' => $parsed['example_url'],
                        'da' => $parsed['da'],
                        'dr' => $parsed['dr'],
                        'traffic' => $parsed['traffic'],
                        'metrics_manual' => true,
                        'metrics_provider' => 'manual',
                        'metrics_fetched_at' => now(),
                        'country' => $parsed['country'],
                        'countries' => $parsed['countries'],
                        'language' => $parsed['language'],
                        'languages' => $parsed['languages'],
                        'category' => $parsed['primary_category'],
                        'categories' => $parsed['categories'],
                        'price' => $parsed['price'],
                        'turnaround_time' => $parsed['turnaround_time'],
                        'publication_time' => $parsed['publication_time'],
                        'link_type' => $parsed['link_type'],
                        'sponsored' => $parsed['sponsored'],
                        'partner_material' => $parsed['partner_material'],
                        'as_you_prefer' => $parsed['as_you_prefer'],
                        'description' => $parsed['description'],
                        'sensitive_prices' => $parsed['sensitive_prices'],
                        'verified' => false,
                        'active' => false,
                        'enrichment_status' => 'pending',
                    ]);
                    $site->save();
                });
                $created++;
            } catch (\Exception $e) {
                Log::error('Bulk site import row failed: '.$e->getMessage(), [
                    'row' => $rowNumber,
                    'user_id' => $publisherId,
                ]);
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? '',
                    'errors' => ['Could not save this row. Please check the data.'],
                ];
            }
        }

        fclose($handle);

        if ($dryRun) {
            $message = "Dry run complete. Processed {$processed} row(s): {$wouldCreate} would be submitted, ".count($failed).' would fail. Nothing was saved.';

            return back()
                ->with($wouldCreate > 0 && count($failed) === 0 ? 'success' : 'error', $message)
                ->with('bulk_import_created', 0)
                ->with('bulk_import_would_create', $wouldCreate)
                ->with('bulk_import_failures', $failed)
                ->with('bulk_import_dry_run', true);
        }

        if ($created > 0) {
            try {
                $user = auth()->user();
                $admins = User::where('active_role_id', function ($query) {
                    $query->select('id')->from('roles')->where('name', 'admin')->limit(1);
                })->get();

                $subject = "Bulk site import: {$created} site(s) from {$user->name}";
                $body = "Publisher {$user->name} ({$user->email}) submitted {$created} website(s) via bulk CSV import.\n"
                    .'Failed rows: '.count($failed)."\n"
                    .'Please review them in the admin Sites panel.';

                $recipients = $admins->count() > 0
                    ? $admins->pluck('email')->all()
                    : [config('mail.admin_email', 'admin@yourdomain.com')];

                foreach ($recipients as $email) {
                    Mail::raw($body, function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    });
                }
            } catch (\Exception $e) {
                Log::error('Bulk import admin notification failed: '.$e->getMessage());
            }
        }

        $message = "{$created} site(s) submitted for review.";
        if (count($failed) > 0) {
            $message .= ' '.count($failed).' row(s) failed — see details below.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('bulk_import_created', $created)
            ->with('bulk_import_failures', $failed);
    }

    /**
     * Normalize + validate one CSV row into site attributes.
     */
    private function normalizeBulkRow(array $data, array $validCategoryNamesLower): array
    {
        $errors = [];

        $siteUrl = $data['site_url'] ?? '';
        if ($siteUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $siteUrl)) {
            $siteUrl = 'https://'.$siteUrl;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        if (! $domain) {
            $errors[] = 'Invalid site_url.';
        }

        $exampleUrl = $data['example_url'] ?? '';
        if ($exampleUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $exampleUrl)) {
            $exampleUrl = 'https://'.$exampleUrl;
        }

        $categoryRaw = $data['categories'] ?? '';
        $categories = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $categoryRaw) ?: [])));
        if (count($categories) < 1) {
            $errors[] = 'At least one category is required (use | or , between names).';
        } elseif (count($categories) > 7) {
            $errors[] = 'Maximum 7 categories allowed.';
        } else {
            foreach ($categories as $cat) {
                if (! in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $errors[] = "Unknown category: {$cat}";
                }
            }
        }

        $countryCodes = array_slice($this->parseCodeList($data['country'] ?? ($data['countries'] ?? '')), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($data['language'] ?? ($data['languages'] ?? '')), 0, 1);
        if (count($countryCodes) < 1) {
            $errors[] = 'A country code is required (e.g. de).';
        }
        if (count($languageCodes) < 1) {
            $errors[] = 'A language code is required (e.g. de).';
        }

        $description = strip_tags((string) ($data['description'] ?? ''), '<p><a><b><strong><i><ul><ol><li><br>');

        $payload = [
            'site_name' => $data['site_name'] ?? '',
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $data['da'] ?? null,
            'dr' => $data['dr'] ?? null,
            'traffic' => $data['traffic'] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
            'price' => $data['price'] ?? null,
            'turnaround_time' => $data['turnaround_time'] ?? '',
            'publication_time' => $data['publication_time'] ?? '',
            'link_type' => strtolower($data['link_type'] ?? ''),
            'description' => $description,
        ];

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        $validator = Validator::make($payload, [
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'countries' => 'required|array|size:1',
            'countries.*' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'languages' => 'required|array|size:1',
            'languages.*' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'required|string|min:50',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $msg) {
                $errors[] = $msg;
            }
        }

        $sensitivePrices = [];
        foreach (['crypto' => 'price_crypto', 'trading' => 'price_trading', 'CBD' => 'price_CBD', 'forex' => 'price_forex'] as $topic => $col) {
            $val = $data[$col] ?? '';
            if ($val !== '' && $val !== null) {
                if (! is_numeric($val) || $val < 0) {
                    $errors[] = "{$col} must be a number ≥ 0.";
                } else {
                    $sensitivePrices[$topic] = (float) $val;
                }
            }
        }

        if (! empty($errors)) {
            return ['errors' => array_values(array_unique($errors))];
        }

        return [
            'errors' => [],
            'site_name' => $payload['site_name'],
            'site_url' => $payload['site_url'],
            'domain' => $domain,
            'example_url' => $payload['example_url'],
            'da' => (int) $payload['da'],
            'dr' => (int) $payload['dr'],
            'traffic' => (int) $payload['traffic'],
            'country' => $countryCodes[0],
            'countries' => $countryCodes,
            'language' => $languageCodes[0],
            'languages' => $languageCodes,
            'primary_category' => implode(',', $categories),
            'categories' => $categories,
            'price' => $payload['price'],
            'turnaround_time' => $payload['turnaround_time'],
            'publication_time' => $payload['publication_time'],
            'link_type' => $payload['link_type'],
            'sponsored' => $this->csvBool($data['sponsored'] ?? '0'),
            'partner_material' => $this->csvBool($data['partner_material'] ?? '0'),
            'as_you_prefer' => $this->csvBool($data['as_you_prefer'] ?? '0'),
            'description' => $description,
            'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
        ];
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

    private function csvBool($value): bool
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }
}
