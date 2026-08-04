<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Services\Catalog\RevealPaceGuard;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Sends an advertiser to a publisher's site without printing its address.
 *
 * The listing shows "Open site" pointing here rather than at the domain, so the
 * page contains nothing to scrape even for sites the reader is free to visit.
 * Evaluation and disclosure stop being the same act: you can look at a site
 * without us publishing its name to everyone who views the row.
 *
 * The address bar will of course show the domain once they arrive, and that is
 * fine. That is one site, one click, attributed to one account — which is why
 * this records the visit as a disclosure and counts toward pace like any other.
 */
class SiteVisitController extends Controller
{
    public function __invoke(
        int $site,
        SiteUrlVisibility $visibility,
        RevealPaceGuard $pace,
    ): RedirectResponse {
        $model = Site::query()->where('active', 1)->find($site);

        if (! $model || blank($model->site_url)) {
            return redirect()
                ->route('advertiser.catalog')
                ->with('error', 'That website is no longer listed.');
        }

        $user = auth()->user();

        try {
            if (! $visibility->canSee($user, $model)) {
                if ($pace->assess($user)['state'] === RevealPaceGuard::FROZEN) {
                    return redirect()
                        ->route('advertiser.catalog')
                        ->with('error', RevealPaceGuard::freezeUserMessage());
                }

                $visibility->reveal($user, $model, SiteUrlReveal::SOURCE_VISIT);
            }
        } catch (\Throwable $e) {
            // Never strand someone on a blank page over bookkeeping.
            Log::warning('Could not record site visit', [
                'site_id' => $site,
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);
        }

        $url = $model->site_url;

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.ltrim($url, '/');
        }

        return redirect()->away($url);
    }
}
