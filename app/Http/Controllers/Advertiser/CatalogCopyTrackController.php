<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogCopyStrikeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records a catalog clipboard copy of URL/domain identity for strike tracking.
 */
class CatalogCopyTrackController extends Controller
{
    public function __invoke(Request $request, CatalogCopyStrikeGuard $guard): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $guard->record(
                $user,
                isset($validated['site_id']) ? (int) $validated['site_id'] : null,
                (string) $validated['text'],
            );

            return response()->json([
                'success' => true,
                ...$result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Catalog copy track failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => CatalogCopyStrikeGuard::STATUS_IGNORED,
                'message' => 'Could not record that copy.',
            ], 500);
        }
    }
}
