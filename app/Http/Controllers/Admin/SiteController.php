<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $needsReviewFilter = $request->boolean('needs_review')
            || $request->query('verified') === '0'
            || $request->query('verified') === 0;

        $query = User::withCount('sites')
            ->withCount(['sites as needs_review_sites_count' => function ($q) {
                $q->needsAdminReview();
            }])
            ->with(['sites' => function ($q) {
                $q->latest();
            }]);

        // Ops queue: publishers with sites ready for admin decision (not unfinished drafts)
        if ($needsReviewFilter) {
            $query->whereHas('sites', function ($q) {
                $q->needsAdminReview();
            })->withCount(['sites as unverified_sites_count' => function ($q) {
                $q->needsAdminReview();
            }]);
        }

        $users = $query->latest()->paginate(20)->appends($request->query());
        $unverifiedFilter = $needsReviewFilter;
        $needsReviewFilterActive = $needsReviewFilter;
        $openReviewCount = Site::query()->needsAdminReview()->count();

        return view('admin.sites', compact(
            'users',
            'unverifiedFilter',
            'needsReviewFilterActive',
            'openReviewCount'
        ));
    }

    /**
     * Admin records sheet: all websites with URL, countries, categories only.
     * Always reads live from the sites table.
     * Optional ?country=de (or other ISO code) filters to that market.
     * ?partial=1 or Accept: application/json returns table HTML for live filter swaps.
     */
    public function records(Request $request)
    {
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        if ($countryFilter === 'all') {
            $countryFilter = '';
        }

        $query = Site::query()->orderBy('domain')->orderBy('id');
        $this->applyRecordsCountryFilter($query, $countryFilter);

        $sites = $query
            ->paginate(100)
            ->appends(array_filter([
                'country' => $countryFilter !== '' ? $countryFilter : null,
            ]))
            ->through(fn (Site $site) => $this->siteRecordRow($site));

        $countryCounts = $this->recordsCountryCounts();
        $totalSites = (int) Site::query()->count();
        $countries = Country::marketplace()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(function (Country $country) use ($countryCounts) {
                $code = strtolower(trim((string) $country->code));

                return [
                    'code' => $code,
                    'name' => (string) $country->name,
                    'count' => (int) ($countryCounts[$code] ?? 0),
                ];
            })
            ->values();

        $selectedCountry = $countryFilter;
        $exportUrl = route('admin.sites.records.export', array_filter([
            'country' => $selectedCountry !== '' ? $selectedCountry : null,
        ]));

        $wantsPartial = $request->boolean('partial')
            || $request->expectsJson()
            || str_contains(strtolower((string) $request->header('Accept', '')), 'application/json');

        if ($wantsPartial) {
            $tableHtml = view('admin.sites.partials.records-table', [
                'sites' => $sites,
                'selectedCountry' => $selectedCountry,
            ])->render();

            return response()->json([
                'success' => true,
                'selected_country' => $selectedCountry,
                'total' => $sites->total(),
                'export_url' => $exportUrl,
                'table_html' => $tableHtml,
            ]);
        }

        return view('admin.sites.records', compact(
            'sites',
            'countries',
            'selectedCountry',
            'totalSites',
            'exportUrl'
        ));
    }

    /**
     * CSV download of the same live records sheet (honours country filter).
     */
    public function exportRecords(Request $request): StreamedResponse
    {
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        if ($countryFilter === 'all') {
            $countryFilter = '';
        }

        $suffix = $countryFilter !== '' ? '-'.$countryFilter : '';
        $filename = 'websites-records'.$suffix.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($countryFilter) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['url', 'countries', 'categories']);

            $query = Site::query()->orderBy('domain')->orderBy('id');
            $this->applyRecordsCountryFilter($query, $countryFilter);

            foreach ($query->cursor() as $site) {
                $row = $this->siteRecordRow($site);
                fputcsv($out, [$row['url'], $row['countries'], $row['categories']]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Tally sites per country code from legacy `country` + JSON `countries`.
     * Multi-market sites increment each matched code.
     *
     * @return array<string, int>
     */
    private function recordsCountryCounts(): array
    {
        $counts = [];

        foreach (Site::query()->select(['country', 'countries'])->cursor() as $site) {
            foreach ($site->countryCodes() as $code) {
                $code = strtolower(trim((string) $code));
                if ($code === '') {
                    continue;
                }
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyRecordsCountryFilter($query, string $countryCode): void
    {
        $code = strtolower(trim($countryCode));
        if ($code === '') {
            return;
        }

        $query->where(function ($q) use ($code) {
            $q->whereRaw('LOWER(country) = ?', [$code])
                ->orWhereJsonContains('countries', $code);
        });
    }

    /**
     * @return array{url: string, countries: string, categories: string}
     */
    private function siteRecordRow(Site $site): array
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $domain = trim((string) ($site->domain ?: ''));
            $url = $domain !== '' ? 'https://'.$domain : '';
        }

        $countries = collect($site->countryCodes())
            ->filter()
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->unique()
            ->values()
            ->implode('|');

        $categories = collect($site->categories_array)
            ->filter()
            ->map(fn ($cat) => trim((string) $cat))
            ->filter()
            ->unique()
            ->values()
            ->implode('|');

        return [
            'url' => $url,
            'countries' => $countries,
            'categories' => $categories,
        ];
    }

    // Get all sites of a user (AJAX)
    public function userSites($id)
    {
        $user = User::with(['sites' => fn ($q) => $q->latest()])->findOrFail($id);

        $sites = $user->sites->map(function (Site $site) {
            $row = $site->toArray();
            $row['needs_review'] = $site->needsAdminReview();
            $row['awaits_publisher_details'] = $site->awaitsPublisherDetails();

            return $row;
        })->values();

        // Include publisher meta so the detail view still loads when the publisher
        // is absent from a filtered "needs review" users table (e.g. after activate).
        return response()->json([
            'publisher' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
            'sites' => $sites,
        ]);
    }

    // Edit page (optional)
    public function edit($id)
    {
        $site = Site::with('publisher:id,name,email')->findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());
        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();

        // Load by absolute path so a stale `view:cache` manifest cannot report
        // "View [admin.site-edit] not found" when the Blade file is on disk.
        $editViewPath = resource_path('views/admin/site-edit.blade.php');
        if (is_file($editViewPath)) {
            return view()->file($editViewPath, compact(
                'site',
                'isMarketingEditor',
                'languages',
                'countries',
                'categories'
            ));
        }

        // Fallback: open the existing Sites UI editor for this publisher/site.
        return redirect()->to(staff_route('sites.index', [
            'publisher' => $site->publisher_id,
            'edit_site' => $site->id,
        ]));
    }

    // Upload image for site
    public function uploadImage(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $request->validate([
            'site_image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        // Delete old image if exists
        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        // Store new image and persist on the site (admin + marketing).
        $file = $request->file('site_image');
        $path = $file->store('sites', 'public');
        $site->update(['site_image' => $path]);

        ActivityLogger::log(
            'site.image_uploaded',
            auth()->user()->name.' uploaded an image for site "'.$site->site_name.'"',
            $site,
            ['image_path' => $path],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'image_path' => $path,
            'message' => 'Image uploaded successfully',
        ]);
    }

    // UPDATE (supports partial + full updates safely)
    public function update(Request $request, $id)
    {
        $site = Site::findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());

        // Store old data for email comparison / activity log
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'language' => $site->language,
            'country' => $site->country,
            'active' => $site->active,
            'verified' => $site->verified,
        ];

        if ($isMarketingEditor) {
            $data = $this->marketingUpdatePayload($request, $site);

            if ($data instanceof JsonResponse) {
                return $data;
            }

            if ($data instanceof RedirectResponse) {
                return $data;
            }
        } else {
            $data = $request->only([
                'site_name',
                'site_url',
                'domain',
                'example_url',
                'da',
                'dr',
                'traffic',
                'country',
                'language',
                'category',
                'price',
                'publication_time',
                'link_type',
                'sponsored',
                'partner_material',
                'as_you_prefer',
                'sensitive_prices',
                'description',
                'active',
                'site_image',
            ]);

            // Derive domain from URL when the edit form omits it.
            if (empty($data['domain']) && ! empty($data['site_url'])) {
                try {
                    $data['domain'] = preg_replace('/^www\./i', '', parse_url($data['site_url'], PHP_URL_HOST) ?: '');
                } catch (\Throwable $e) {
                    $data['domain'] = null;
                }
                if ($data['domain'] === '') {
                    $data['domain'] = null;
                }
            }

            // Manual metric edits from admin — mark as manual so auto-refresh does not overwrite.
            if ($request->hasAny(['da', 'dr', 'traffic'])) {
                $data['metrics_manual'] = true;
                $data['metrics_provider'] = 'manual';
                $data['metrics_fetched_at'] = now();
                $data['enrichment_status'] = 'ready';
            }

            // Multipart form upload from the dedicated edit page.
            if ($request->hasFile('site_image')) {
                $request->validate([
                    'site_image' => 'image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                ]);

                if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
                    Storage::disk('public')->delete($site->site_image);
                }

                $data['site_image'] = $request->file('site_image')->store('sites', 'public');
            } elseif ($request->has('site_image') && $request->site_image !== null) {
                // JSON/AJAX path: image path already uploaded via upload-image.
                $data['site_image'] = $request->site_image;
            } else {
                unset($data['site_image']);
            }

            // Prevent overwriting NOT NULL fields with null
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            if (isset($data['description']) && is_string($data['description'])) {
                $data['description'] = app(SiteDescriptionSanitizer::class)
                    ->sanitize($data['description']);
            }
        }

        $site->update($data);

        $changes = [];
        foreach ($oldData as $key => $oldValue) {
            $newValue = $site->{$key} ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                $changes[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        ActivityLogger::log(
            'site.updated',
            auth()->user()->name.' modified site "'.$site->site_name.'"',
            $site,
            ['changes' => $changes],
            $site->site_name
        );

        $emailSent = false;

        // Send email notification to publisher about the update
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'update', $oldData));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send update notification: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site updated successfully',
                'email_sent' => $emailSent,
            ]);
        }

        $message = 'Site updated successfully.'.($emailSent ? ' Publisher notified.' : '');

        return redirect()
            ->to(staff_route('sites.edit', $site->id))
            ->with('success', $message);
    }

    /**
     * Marketing may only edit metrics, geo, and niches for the bulk handoff.
     *
     * @return array<string, mixed>|JsonResponse|RedirectResponse
     */
    private function marketingUpdatePayload(Request $request, Site $site)
    {
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $validCategoryNames = Category::query()->pluck('name')->all();
        $validCategoryNamesLower = array_map(fn ($n) => strtolower((string) $n), $validCategoryNames);
        $categoryNameByLower = [];
        foreach ($validCategoryNames as $name) {
            $categoryNameByLower[strtolower((string) $name)] = (string) $name;
        }

        $categories = $this->parseCategoryList($request->input('categories', []));
        $request->merge(['categories' => $categories]);

        $validator = Validator::make($request->all(), [
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'language' => 'required|string|max:10',
            'country' => 'required|string|max:10',
            'categories' => 'required|array|min:1|max:7',
            'site_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $validator->after(function ($validator) use ($request, $allowedCountries, $allowedLanguages, $validCategoryNamesLower) {
            $language = strtolower(trim((string) $request->input('language', '')));
            $country = strtolower(trim((string) $request->input('country', '')));

            if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                $validator->errors()->add('language', 'Choose a valid marketplace language.');
            }
            if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                $validator->errors()->add('country', 'Choose a valid marketplace country.');
            }

            foreach ((array) $request->input('categories', []) as $cat) {
                if (! in_array(strtolower((string) $cat), $validCategoryNamesLower, true)) {
                    $validator->errors()->add('categories', 'Unknown niche: '.$cat);
                }
            }
        });

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $language = strtolower(trim((string) $request->input('language')));
        $country = strtolower(trim((string) $request->input('country')));
        $categories = array_values(array_filter(array_map(
            fn ($cat) => $categoryNameByLower[strtolower((string) $cat)] ?? (string) $cat,
            $categories
        )));

        $payload = [
            'da' => (int) $request->input('da'),
            'dr' => (int) $request->input('dr'),
            'traffic' => (int) $request->input('traffic'),
            'language' => $language,
            'languages' => [$language],
            'country' => $country,
            'countries' => [$country],
            'category' => Site::fitCategoryColumn(implode('|', $categories), $categories),
            'categories' => $categories,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
        ];

        // Same image rules as admin — optional; leave empty to keep current.
        if ($request->hasFile('site_image')) {
            if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
                Storage::disk('public')->delete($site->site_image);
            }

            $payload['site_image'] = $request->file('site_image')->store('sites', 'public');
        }

        return $payload;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function parseCategoryList($raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw)));
        }

        $str = trim((string) $raw);
        if ($str === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\|/', $str) ?: [])));
    }

    // VERIFY / UNVERIFY (approve / reject) — admin only
    public function verify(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can verify or unverify sites.',
            ], 403);
        }

        $approving = (bool) (int) $request->verified;
        $reason = $this->validatedStatusReason($request, ! $approving);

        $site = Site::findOrFail($id);

        // Heal complete drafts; admin approve also clears incomplete awaiting_details.
        $site->promoteFromAwaitingDetailsIfComplete();
        $site->refresh();
        if ($approving && $site->awaitsPublisherDetails()) {
            $site->clearAwaitingDetailsForAdmin();
            $site->refresh();
        }

        $oldStatus = (int) $site->verified;
        $site->verified = $approving ? 1 : 0;
        if ($site->verified) {
            $site->verified_at = now();
            $site->verify_method = 'manual';
            $site->verify_token = null;
            $site->verify_token_created_at = null;
            // Leave the review/onboarding queue once approved.
            $site->onboarding_status = null;
        } else {
            $site->verified_at = null;
            $site->verify_method = null;
            Site::ensureStatusReasonColumns();
            $this->applyStatusReason($site, $reason);
        }
        $site->save();

        $action = $site->verified ? 'site.approved' : 'site.rejected';
        $label = $site->verified ? 'approved' : 'rejected';

        ActivityLogger::log(
            $action,
            auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
            $site,
            [
                'from' => $oldStatus,
                'to' => (int) $site->verified,
                'bulk_site_request_id' => $site->bulk_site_request_id,
                'reason' => $reason,
            ],
            $site->site_name
        );

        // After verification: always refresh homepage screenshot.
        // Skip automated metrics when the publisher entered DA/DR/traffic manually.
        if ($site->verified && config('site_enrichment.enabled', true)) {
            $runMetrics = ! (bool) $site->metrics_manual;
            EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
        }

        // Verify / unverify is an admin decision — clear open review reminders for this site.
        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications after verify: '.$e->getMessage());
        }

        $emailSent = false;
        $status = $site->verified ? 'verified' : 'unverified';
        $notifyReason = $approving ? null : $reason;

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                $emailSent = true;
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send verification notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification updated',
            'email_sent' => $emailSent,
        ]);
    }

    // TOGGLE ACTIVE STATUS — admin and marketing (shared Sites Management)
    public function toggleActive(Request $request, $id)
    {
        $actor = auth()->user();
        if (! $actor?->canActivateSites()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to activate or deactivate sites.',
            ], 403);
        }

        try {
            $site = Site::findOrFail($id);
            $activating = (bool) (int) $request->active;
            // Must not be swallowed by the catch below — UI expects 422 + errors.reason.
            $reason = $this->validatedStatusReason($request, ! $activating);

            // Heal complete drafts; staff activate also clears incomplete awaiting_details
            // so marketing can finish the same flow as admin from Sites Management.
            if ($activating) {
                $site->promoteFromAwaitingDetailsIfComplete();
                $site->refresh();
                if ($site->awaitsPublisherDetails()) {
                    $site->clearAwaitingDetailsForAdmin();
                    $site->refresh();
                }
            }

            $oldStatus = (int) $site->active;
            $site->active = $activating ? 1 : 0;
            if ($activating) {
                // Leave the review/onboarding queue once live.
                $site->onboarding_status = null;
            } else {
                Site::ensureStatusReasonColumns();
                $this->applyStatusReason($site, $reason);
            }
            $site->save();

            $action = $site->active ? 'site.activated' : 'site.deactivated';
            $label = $site->active ? 'activated' : 'deactivated';

            ActivityLogger::log(
                $action,
                ($actor->name ?? 'Staff').' '.$label.' site "'.$site->site_name.'"',
                $site,
                [
                    'from' => $oldStatus,
                    'to' => (int) $site->active,
                    'bulk_site_request_id' => $site->bulk_site_request_id,
                    'by_role' => $actor->activeRole(),
                    'reason' => $reason,
                ],
                $site->site_name
            );

            // Activate / deactivate counts as an admin decision for the open review task.
            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications after active toggle: '.$e->getMessage());
            }

            $emailSent = false;
            $status = $site->active ? 'activated' : 'deactivated';
            $notifyReason = $activating ? null : $reason;

            try {
                $publisher = $site->publisher;
                if ($publisher && $publisher->email) {
                    Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                    $emailSent = true;
                }
                if ($publisher) {
                    app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send status notification: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $activating ? 'Site activated' : 'Site deactivated',
                'email_sent' => $emailSent,
                'active' => (bool) $site->active,
                'reason' => $notifyReason,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to toggle site active status', [
                'site_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $hint = '';
            if (str_contains($e->getMessage(), 'onboarding_status')) {
                $hint = ' Run database/sql/fix_sites_onboarding_status.sql on the database if this persists.';
            } elseif (str_contains($e->getMessage(), 'status_reason')) {
                $hint = ' Run database/sql/add_sites_status_reason.sql on the database if this persists.';
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not update active status.'.$hint,
            ], 500);
        }
    }

    /**
     * @return string|null Trimmed reason when provided; null when not required / empty optional.
     */
    private function validatedStatusReason(Request $request, bool $required): ?string
    {
        $rules = $required
            ? ['reason' => ['required', 'string', 'min:10', 'max:1000']]
            : ['reason' => ['nullable', 'string', 'max:1000']];

        $data = $request->validate($rules);
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        return $reason !== '' ? $reason : null;
    }

    private function applyStatusReason(Site $site, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }

        $site->status_reason = $reason;
        $site->status_reason_at = now();
        $site->status_reason_by = auth()->id();
    }

    // DELETE — admin: any site; marketing: pending / not-live only
    public function destroy(Request $request, $id)
    {
        $user = auth()->user();
        $site = Site::findOrFail($id);

        $isAdmin = (bool) $user?->isAdmin();
        $isMarketingPendingDelete = (bool) $user?->isMarketing() && $site->canBeDeletedByMarketing();

        if (! $isAdmin && ! $isMarketingPendingDelete) {
            return response()->json([
                'success' => false,
                'message' => $user?->isMarketing()
                    ? 'Marketing can only delete pending sites that are not verified or active in the portal.'
                    : 'Only admins can delete sites.',
            ], 403);
        }

        $siteName = $site->site_name;
        $siteId = $site->id;
        $domain = $site->domain;
        $bulkRequestId = $site->bulk_site_request_id;
        $onboarding = $site->onboarding_status;

        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications before delete: '.$e->getMessage());
        }

        // Deleting is how staff reject a submission outright, so the publisher
        // needs the same courtesy as a deactivation — otherwise their site just
        // vanishes and the first they hear of it is when they come looking.
        // Captured before delete(): the mailable and bell both read the model.
        $publisher = $site->publisher;
        $rejectionReason = $request->input('reason') ?: $site->status_reason;
        $notifySnapshot = clone $site;

        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        $site->delete();

        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(
                    new SiteStatusNotification($notifySnapshot, 'removed', null, $rejectionReason)
                );
            }
            if ($publisher) {
                app(InAppNotificationService::class)
                    ->notifySiteStatusChanged($notifySnapshot, 'removed', $rejectionReason);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after site delete: '.$e->getMessage());
        }

        ActivityLogger::log(
            $isMarketingPendingDelete && ! $isAdmin ? 'site.deleted_by_marketing' : 'site.deleted',
            ($user->name ?? 'Staff').' deleted site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
            null,
            [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'domain' => $domain,
                'bulk_site_request_id' => $bulkRequestId,
                'onboarding_status' => $onboarding,
                'deleted_by_role' => $user?->activeRole(),
            ],
            $siteName
        );

        return response()->json([
            'success' => true,
            'message' => 'Site deleted successfully',
        ]);
    }
}
