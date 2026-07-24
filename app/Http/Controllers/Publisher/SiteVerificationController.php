<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\SiteFileVerificationService;
use Illuminate\Http\Request;

class SiteVerificationController extends Controller
{
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
            ]);
        }

        if ($site->awaitsPublisherDetails()) {
            return response()->json([
                'success' => false,
                'message' => 'Finish required site details before requesting verification.',
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
            ...$payload,
        ]);
    }

    /**
     * Automatically verify the site if the public .txt file matches.
     */
    public function check($id)
    {
        $site = $this->ownedSite($id);
        $result = $this->verification->check($site->fresh());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function ownedSite($id): Site
    {
        return Site::query()
            ->where('publisher_id', auth()->id())
            ->findOrFail($id);
    }
}
