<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\SiteFileVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SiteVerificationController extends Controller
{
    public const CHECK_MAX_ATTEMPTS = 15;

    public const CHECK_DECAY_SECONDS = 600;

    public function __construct(
        private SiteFileVerificationService $verification,
    ) {}

    /**
     * Show / generate the verification file instructions for a site.
     */
    public function start(Request $request, $id)
    {
        $site = $this->ownedSite($id);

        if ($site->verified) {
            return response()->json([
                'success' => true,
                'verified' => true,
                'message' => 'This website is already verified.',
                'file_url' => $this->verification->verificationFileUrl($site),
                'error_code' => null,
            ]);
        }

        if ($site->awaitsPublisherDetails()) {
            return response()->json([
                'success' => false,
                'message' => 'Finish required site details before requesting verification.',
                'error_code' => SiteFileVerificationService::ERROR_INCOMPLETE,
            ], 422);
        }

        if ($site->isPendingPublisherAcceptance()) {
            return response()->json([
                'success' => false,
                'message' => 'Accept this staff-added site before requesting verification.',
                'error_code' => 'pending_acceptance',
            ], 422);
        }

        $regenerate = $request->boolean('regenerate');
        $payload = $this->verification->start($site, $regenerate);

        return response()->json([
            'success' => true,
            'verified' => false,
            'message' => $regenerate
                ? 'New verification code generated. Update your file, then check again.'
                : 'Upload the verification file, then click Check verification.',
            'error_code' => null,
            ...$payload,
        ]);
    }

    /**
     * Automatically verify the site if the public .txt file matches.
     */
    public function check($id)
    {
        $site = $this->ownedSite($id);

        // Already verified — never burn rate-limit budget.
        if ($site->verified) {
            return response()->json([
                'success' => true,
                'verified' => true,
                'message' => 'This website is already verified.',
                'file_url' => $this->verification->verificationFileUrl($site),
                'error_code' => null,
            ]);
        }

        $key = $this->checkRateLimitKey($site->id);

        if (RateLimiter::tooManyAttempts($key, self::CHECK_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'verified' => false,
                'message' => 'Too many verification checks. Please wait '.max(1, (int) ceil($seconds / 60)).' minute(s) and try again.',
                'file_url' => $this->verification->verificationFileUrl($site),
                'error_code' => 'rate_limited',
                'retry_after' => $seconds,
            ], 422);
        }

        $result = $this->verification->check($site->fresh());

        if (! empty($result['verified'])) {
            RateLimiter::clear($key);
        } elseif (($result['error_code'] ?? null) !== SiteFileVerificationService::ERROR_INCOMPLETE) {
            // Count real fetch/mismatch failures only (not incomplete-draft short-circuits).
            RateLimiter::hit($key, self::CHECK_DECAY_SECONDS);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function checkRateLimitKey(int $siteId): string
    {
        return 'site-verify-check:'.(int) auth()->id().':'.$siteId;
    }

    private function ownedSite($id): Site
    {
        return Site::query()
            ->where('publisher_id', auth()->id())
            ->findOrFail($id);
    }
}
