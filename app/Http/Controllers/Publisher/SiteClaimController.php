<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class SiteClaimController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'site_id' => 'nullable|integer|exists:sites,id',
            'website_url' => 'required_without:site_id|nullable|url|max:255',
            'website_name' => 'nullable|string|max:190',
            'proof_message' => 'required|string|min:20|max:3000',
            'contact_email' => 'nullable|email|max:190',
        ]);

        $site = null;
        if (! empty($data['site_id'])) {
            $site = Site::find($data['site_id']);
        } else {
            $domain = $this->extractDomain((string) ($data['website_url'] ?? ''));
            if (! $domain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please enter a valid website URL.',
                ], 422);
            }
            $site = Site::where('domain', $domain)->first();
        }

        if (! $site) {
            return response()->json([
                'success' => false,
                'message' => 'We could not find that website in our catalog.',
            ], 422);
        }

        if ((int) $site->publisher_id === (int) auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You already own this listing.',
            ], 422);
        }

        $pending = SiteClaim::query()
            ->where('site_id', $site->id)
            ->where('claimer_id', auth()->id())
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending claim for this website. We’ll email you after review.',
            ], 422);
        }

        $websiteUrl = $data['website_url'] ?? $site->site_url;
        if (! $websiteUrl && $site->domain) {
            $websiteUrl = 'https://'.$site->domain;
        }

        $websiteName = trim((string) ($data['website_name'] ?? ''));
        if ($websiteName === '') {
            $websiteName = (string) $site->site_name;
        }

        $domain = $site->domain ?: $this->extractDomain((string) $websiteUrl);
        $nameMatches = $this->namesMatch($websiteName, (string) $site->site_name);

        $claim = SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => auth()->id(),
            'website_name' => $websiteName,
            'website_url' => $websiteUrl,
            'domain' => $domain,
            'name_matches' => $nameMatches,
            'proof_message' => $data['proof_message'],
            'contact_email' => $data['contact_email'] ?? auth()->user()->email,
            'status' => 'pending',
        ]);

        try {
            ActivityLogger::log(
                'site.claim_submitted',
                auth()->user()->name.' claimed ownership of '.$site->site_name,
                $site,
                [
                    'claim_id' => $claim->id,
                    'name_matches' => $nameMatches,
                    'provided_name' => $websiteName,
                    'via' => ! empty($data['site_id']) ? 'catalog' : 'url',
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => $nameMatches
                ? 'Claim submitted. The website name matches our listing — our team will verify ownership and get back to you.'
                : 'Claim submitted. The website name you entered does not exactly match our listing, so we will verify carefully before transferring ownership.',
            'name_matches' => $nameMatches,
        ]);
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    private function namesMatch(string $provided, string $listed): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;

            return $value;
        };

        return $normalize($provided) === $normalize($listed);
    }
}
