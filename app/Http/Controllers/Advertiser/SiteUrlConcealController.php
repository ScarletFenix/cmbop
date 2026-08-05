<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Catalog\SiteUrlVisibility;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Hides a publisher domain the advertiser has already opened.
 *
 * The disclosure row stays — this only flips their display preference — so a
 * refresh keeps the address masked until they click the eye again.
 */
class SiteUrlConcealController extends Controller
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

            // Staff / the listing's publisher always see the real host; there is
            // nothing useful to "hide" for them in the catalog UI.
            if ($user->isAdmin() || $user->isMarketing() || (int) $model->publisher_id === (int) $user->id) {
                return response()->json([
                    'success' => true,
                    'masked' => $visibility->mask($model->site_url),
                ]);
            }

            if (! $visibility->hasEverSeen($user, $model)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Open the address before you can hide it.',
                ], 422);
            }

            $visibility->conceal($user, $model);

            return response()->json([
                'success' => true,
                'masked' => $visibility->mask($model->site_url),
            ]);
        } catch (\Throwable $e) {
            Log::error('Site URL conceal failed', [
                'site_id' => $site,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not hide that website address'),
            ], 500);
        }
    }
}
