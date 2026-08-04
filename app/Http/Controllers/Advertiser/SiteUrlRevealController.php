<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Hands an advertiser one publisher domain at a time.
 *
 * The whole point of masking is that the real value never leaves the server
 * unasked, so this is the only route that returns one — one site per request,
 * authenticated, logged, and counted against a daily allowance.
 */
class SiteUrlRevealController extends Controller
{
    public function __invoke(int $site, SiteUrlVisibility $visibility): JsonResponse
    {
        try {
            $user = auth()->user();
            $model = Site::query()->where('active', 1)->find($site);

            if (! $model) {
                return response()->json([
                    'success' => false,
                    'message' => 'That website is no longer listed.',
                ], 404);
            }

            // Already seen, or theirs to begin with: no allowance, no new row.
            if ($visibility->canSee($user, $model)) {
                return response()->json([
                    'success' => true,
                    'url' => $visibility->host($model->site_url),
                    'full_url' => $model->site_url,
                    'remaining' => $visibility->remainingAllowance($user),
                ]);
            }

            if (! $visibility->hasAllowanceLeft($user)) {
                return response()->json([
                    'success' => false,
                    'code' => 'allowance_exhausted',
                    'message' => 'You have opened the daily limit of website addresses. '
                        .'It resets in 24 hours, and adding funds to your wallet removes the limit entirely.',
                    'remaining' => 0,
                ], 429);
            }

            $host = $visibility->reveal($user, $model);

            return response()->json([
                'success' => true,
                'url' => $host,
                'full_url' => $model->site_url,
                'remaining' => $visibility->remainingAllowance($user),
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
