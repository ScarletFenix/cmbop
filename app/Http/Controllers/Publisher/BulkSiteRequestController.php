<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\BulkSiteRequestSubmitted;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\SiteDescriptionSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class BulkSiteRequestController extends Controller
{
    public function store(Request $request)
    {
        $open = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])
            ->exists();

        if ($open) {
            return redirect()
                ->route('publisher.websites')
                ->with('error', 'You already have an open bulk request. Wait for our team to finish it, or message support.');
        }

        $validator = Validator::make($request->all(), [
            'sites' => 'required|array|min:2|max:200',
            'sites.*.url' => 'nullable|string|max:512',
            'sites.*.price' => 'nullable|numeric|min:0|max:999999.99',
            'publisher_note' => 'nullable|string|max:2000',
        ], [
            'sites.required' => 'Add at least two websites (URL + price).',
            'sites.min' => 'Add at least two websites (URL + price).',
        ]);

        $parsedRows = [];
        $validator->after(function ($validator) use ($request, &$parsedRows) {
            $rawSites = $request->input('sites', []);
            if (! is_array($rawSites)) {
                return;
            }

            $seenDomains = [];
            foreach ($rawSites as $index => $row) {
                $urlRaw = trim((string) ($row['url'] ?? ''));
                $priceRaw = $row['price'] ?? null;
                if ($urlRaw === '' && ($priceRaw === null || $priceRaw === '')) {
                    continue;
                }

                $siteUrl = $this->normalizeHttpUrl($urlRaw);
                $host = parse_url($siteUrl, PHP_URL_HOST);
                $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;

                if (! $domain || ! filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                    $validator->errors()->add("sites.$index.url", 'Enter a valid website URL.');

                    continue;
                }

                if (isset($seenDomains[$domain])) {
                    $validator->errors()->add("sites.$index.url", "Duplicate domain in this list: {$domain}");

                    continue;
                }
                $seenDomains[$domain] = true;

                if (Site::where('domain', $domain)->exists()) {
                    $validator->errors()->add("sites.$index.url", "Already registered: {$domain}");

                    continue;
                }

                if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $validator->errors()->add("sites.$index.price", 'Enter a valid price.');

                    continue;
                }

                $parsedRows[] = [
                    'site_url' => $siteUrl,
                    'domain' => $domain,
                    'price' => round((float) $priceRaw, 2),
                ];
            }

            if (count($parsedRows) < 2) {
                $validator->errors()->add('sites', 'Add at least two websites with URL and price.');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('publisher.websites')
                ->withErrors($validator)
                ->withInput()
                ->with('open_bulk_request_modal', true);
        }

        $bulk = DB::transaction(function () use ($request, $parsedRows) {
            $bulk = BulkSiteRequest::create([
                'publisher_id' => auth()->id(),
                'status' => BulkSiteRequest::STATUS_REQUESTED,
                'estimated_count' => count($parsedRows),
                'publisher_note' => $request->publisher_note,
            ]);

            foreach ($parsedRows as $row) {
                BulkSiteRequestItem::create([
                    'bulk_site_request_id' => $bulk->id,
                    'site_url' => $row['site_url'],
                    'domain' => $row['domain'],
                    'price' => $row['price'],
                ]);
            }

            return $bulk;
        });

        ActivityLogger::log(
            'bulk_request.created',
            (auth()->user()->name ?? 'Publisher').' submitted '.count($parsedRows).' site URL(s) + price(s) for bulk onboarding',
            $bulk,
            [
                'bulk_site_request_id' => $bulk->id,
                'publisher_id' => $bulk->publisher_id,
                'estimated_count' => $bulk->estimated_count,
                'domains' => array_column($parsedRows, 'domain'),
            ],
            'Bulk request #'.$bulk->id
        );

        try {
            $admins = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'marketing']))
                ->get();

            $recipients = $admins->isNotEmpty()
                ? $admins
                : collect([(object) ['email' => config('mail.admin_email', 'admin@yourdomain.com')]]);

            foreach ($recipients as $admin) {
                if (! empty($admin->email)) {
                    Mail::to($admin->email)->send(new BulkSiteRequestSubmitted($bulk->load('items')));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to email admins about bulk site request: '.$e->getMessage());
        }

        try {
            app(InAppNotificationService::class)->notifyStaffBulkSiteRequestSubmitted($bulk->load('items', 'publisher'));
        } catch (\Throwable $e) {
            Log::warning('Failed to send in-app bulk request notice: '.$e->getMessage());
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', 'Bulk sites submitted (URL + price). They appear under Pending while our marketer prepares them; then you’ll finish descriptions and listing details; we approve.');
    }

    public function completeIndex()
    {
        $sites = Site::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->orderByDesc('id')
            ->get()
            ->sortBy(fn (Site $s) => $s->awaitsPublisherDetails() ? 0 : 1)
            ->values();

        $openRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('status', [
                BulkSiteRequest::STATUS_SEEDED,
                BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ])
            ->latest()
            ->first();

        $detailsCompleteCount = $sites->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)->count();
        $awaitingCount = $sites->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)->count();

        return view('publisher.bulk-complete', compact(
            'sites',
            'openRequest',
            'detailsCompleteCount',
            'awaitingCount'
        ));
    }

    public function completeStore(Request $request, int $id)
    {
        $site = Site::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('onboarding_status', [
                Site::ONBOARDING_AWAITING_DETAILS,
                Site::ONBOARDING_DETAILS_COMPLETE,
            ])
            ->findOrFail($id);

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl((string) $request->input('exampleUrl')),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'site_tag' => 'nullable|in:sponsored,partner_material,as_you_prefer',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $existingCategories = collect($site->categories ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
            ->values()
            ->all();

        $validator->after(function ($validator) use ($existingCategories) {
            if ($existingCategories === []) {
                $validator->errors()->add(
                    'categories',
                    'Niches are missing for this site. Contact support so marketing can add them before you submit.'
                );
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('publisher.bulk-sites.complete')
                ->withErrors($validator)
                ->withInput()
                ->with('complete_site_id', $site->id);
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize((string) $request->siteDescription);

        DB::transaction(function () use ($site, $request, $cleanDescription, $existingCategories) {
            $sensitivePrices = [];
            foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                if ($request->input("sensitive.$topic")) {
                    $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                }
            }

            $site->applyMarketplaceListing([
                'example_url' => $request->exampleUrl,
                // Niches were set by marketing during Done / metrics edit — keep them.
                'category' => Site::fitCategoryColumn(implode('|', $existingCategories), $existingCategories),
                'categories' => $existingCategories,
                'turnaround_time' => $request->turnaround_time,
                'publication_time' => $request->publicationTime,
                'link_type' => $request->link_type,
                'description' => $cleanDescription,
                'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                'verified' => false,
                'active' => false,
                // Saved for Review & submit — not yet in the admin queue.
                'onboarding_status' => Site::ONBOARDING_DETAILS_COMPLETE,
            ]);

            $tag = $request->input('site_tag', 'as_you_prefer');
            $site->sponsored = $tag === 'sponsored';
            $site->partner_material = $tag === 'partner_material';
            $site->as_you_prefer = $tag === 'as_you_prefer' || $tag === null || $tag === '';

            $site->save();
        });

        $site->refresh();
        if ($site->bulk_site_request_id) {
            $site->bulkSiteRequest?->refreshProgressStatus();
        }

        $remainingAwaiting = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        if ($remainingAwaiting > 0) {
            return redirect()
                ->route('publisher.bulk-sites.complete')
                ->with('success', '“'.$site->site_name.'” saved. Finish the remaining sites, then review & submit.');
        }

        return redirect()
            ->route('publisher.bulk-sites.review')
            ->with('success', '“'.$site->site_name.'” saved. Review your sites below, then submit for admin review.');
    }

    /**
     * Final checklist before sites enter the admin review queue.
     */
    public function reviewIndex()
    {
        $sites = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)
            ->orderByDesc('id')
            ->get();

        $awaitingCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        $openRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereIn('status', [
                BulkSiteRequest::STATUS_SEEDED,
                BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            ])
            ->latest()
            ->first();

        return view('publisher.bulk-review', compact('sites', 'awaitingCount', 'openRequest'));
    }

    /**
     * Submit selected (or all) details_complete sites for admin review.
     */
    public function submitForReview(Request $request)
    {
        $validated = $request->validate([
            'site_ids' => 'nullable|array',
            'site_ids.*' => 'integer',
            'submit_all' => 'nullable|boolean',
        ]);

        $query = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE);

        if (! ($validated['submit_all'] ?? false)) {
            $ids = array_values(array_unique(array_map('intval', $validated['site_ids'] ?? [])));
            if ($ids === []) {
                return redirect()
                    ->route('publisher.bulk-sites.review')
                    ->with('error', 'Select at least one site to submit, or use Submit all.');
            }
            $query->whereIn('id', $ids);
        }

        $sites = $query->get();
        if ($sites->isEmpty()) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('error', 'No sites ready to submit. Complete details first.');
        }

        $submitted = 0;
        $bulkIds = [];

        DB::transaction(function () use ($sites, &$submitted, &$bulkIds) {
            foreach ($sites as $site) {
                if (! $site->hasCompletedPublisherDetails()) {
                    continue;
                }

                $site->onboarding_status = Site::ONBOARDING_READY_FOR_REVIEW;
                $site->save();
                $submitted++;

                if ($site->bulk_site_request_id) {
                    $bulkIds[$site->bulk_site_request_id] = true;
                }
            }
        });

        foreach (array_keys($bulkIds) as $bulkId) {
            BulkSiteRequest::find($bulkId)?->refreshProgressStatus();
        }

        $notifications = app(InAppNotificationService::class);
        foreach ($sites as $site) {
            $site->refresh();
            if ($site->onboarding_status !== Site::ONBOARDING_READY_FOR_REVIEW) {
                continue;
            }
            try {
                $notifications->notifyAdminsNewSite($site, 'create');
            } catch (\Throwable $e) {
                Log::warning('Failed admin bell for bulk review submit: '.$e->getMessage());
            }
        }

        if ($submitted === 0) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('error', 'None of the selected sites have complete details yet.');
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', $submitted === 1
                ? '1 site submitted for admin review — it stays in Pending until approved.'
                : $submitted.' sites submitted for admin review — they stay in Pending until approved.');
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
