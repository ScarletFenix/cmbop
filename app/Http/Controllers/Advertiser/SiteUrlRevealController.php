<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\RevealPaceGuard;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Hands an advertiser one publisher domain.
 *
 * There is no quota. Someone researching a campaign may work through hundreds of
 * listings and should never be told to come back tomorrow. What is checked is
 * the pace: a rate no person sustains earns a pause, not a refusal.
 */
class SiteUrlRevealController extends Controller
{
    public function __invoke(
        int $site,
        SiteUrlVisibility $visibility,
        RevealPaceGuard $pace,
    ): JsonResponse {
        try {
            $user = auth()->user();
            $model = Site::query()->where('active', 1)->find($site);

            if (! $model) {
                return response()->json([
                    'success' => false,
                    'message' => 'That website is no longer listed.',
                ], 404);
            }

            // Already seen, or theirs to begin with: no new disclosure, so the
            // pace check does not apply.
            if ($visibility->canSee($user, $model)) {
                return response()->json([
                    'success' => true,
                    'url' => $visibility->host($model->site_url),
                ]);
            }

            $verdict = $pace->assess($user);

            if ($verdict['state'] === RevealPaceGuard::FROZEN) {
                return response()->json([
                    'success' => false,
                    'code' => 'paused',
                    'message' => 'We have paused new website addresses on this account for a short while. '
                        .'Everything you have already opened stays available, and you can keep browsing and ordering. '
                        .'If you are working through a large shortlist, contact us and we will lift this.',
                ], 429)->header('Retry-After', (string) ($verdict['retry_after'] ?? 300));
            }

            if ($verdict['state'] === RevealPaceGuard::SLOW) {
                // Not a refusal. The client waits a moment and asks again, which
                // a person barely notices and a script cannot afford.
                return response()->json([
                    'success' => false,
                    'code' => 'slow_down',
                    'retry_after' => $verdict['retry_after'],
                    'message' => 'One moment…',
                ], 429)->header('Retry-After', (string) ($verdict['retry_after'] ?? 3));
            }

            return response()->json([
                'success' => true,
                'url' => $visibility->reveal($user, $model),
            ]);
        } catch (\Throwable $e) {
            Log::error('Site URL reveal failed', [
                'site_id' => $site,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not open that website address'),
            ], 500);
        }
    }
}
