<?php

namespace App\Services;

use App\Jobs\EnrichSiteJob;
use App\Mail\SiteStatusNotification;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SiteFileVerificationService
{
    public const FILE_NAME = 'seolinkbuildings-verify.txt';

    public const METHOD_FILE = 'file';

    public const METHOD_MANUAL = 'manual';

    /**
     * Ensure the site has a verification token and return instructions payload.
     *
     * @return array{token: string, file_name: string, file_url: string, regenerated: bool}
     */
    public function start(Site $site, bool $regenerate = false): array
    {
        $regenerated = false;

        if ($regenerate || blank($site->verify_token)) {
            $site->verify_token = $this->generateToken();
            $site->verify_token_created_at = now();
            $site->save();
            $regenerated = true;
        }

        return [
            'token' => (string) $site->verify_token,
            'file_name' => self::FILE_NAME,
            'file_url' => $this->verificationFileUrl($site),
            'regenerated' => $regenerated,
        ];
    }

    /**
     * Fetch the public verification file and auto-verify on exact token match.
     *
     * @return array{success: bool, verified: bool, message: string, file_url: string, status?: int}
     */
    public function check(Site $site): array
    {
        if ($site->verified) {
            return [
                'success' => true,
                'verified' => true,
                'message' => 'This website is already verified.',
                'file_url' => $this->verificationFileUrl($site),
            ];
        }

        if ($site->awaitsPublisherDetails()) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Finish required site details before requesting verification.',
                'file_url' => $this->verificationFileUrl($site),
            ];
        }

        if (blank($site->verify_token)) {
            $this->start($site);
            $site->refresh();
        }

        $fileUrl = $this->verificationFileUrl($site);
        $fetch = $this->fetchVerificationFile($site);

        if (! $fetch['ok']) {
            return [
                'success' => false,
                'verified' => false,
                'message' => $fetch['message'],
                'file_url' => $fileUrl,
                'status' => $fetch['status'] ?? null,
            ];
        }

        $expected = trim((string) $site->verify_token);
        $found = trim((string) $fetch['body']);

        if ($found !== $expected) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'We found the file, but the code does not match. Make sure the file contains only your verification code, then try again.',
                'file_url' => $fileUrl,
                'status' => $fetch['status'] ?? 200,
            ];
        }

        $this->markVerified($site, self::METHOD_FILE);

        return [
            'success' => true,
            'verified' => true,
            'message' => 'Website verified! Your Verified badge is now live on this listing.',
            'file_url' => $fileUrl,
        ];
    }

    public function markVerified(Site $site, string $method = self::METHOD_FILE): void
    {
        $site->verified = true;
        $site->verified_at = now();
        $site->verify_method = $method;
        $site->verify_token = null;
        $site->verify_token_created_at = null;
        $site->save();

        ActivityLogger::log(
            'site.verified_'.$method,
            'Site "'.$site->site_name.'" verified via '.$method,
            $site,
            [
                'method' => $method,
                'domain' => $site->domain,
            ],
            $site->site_name
        );

        if (config('site_enrichment.enabled', true)) {
            $runMetrics = ! (bool) $site->metrics_manual;
            EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
        }

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site->fresh(), 'verified'));
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), 'verified');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after file verification', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function verificationFileUrl(Site $site): string
    {
        return rtrim($this->homepageBaseUrl($site), '/').'/'.self::FILE_NAME;
    }

    public function homepageBaseUrl(Site $site): string
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $url = 'https://'.ltrim((string) $site->domain, '/');
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? ltrim((string) $site->domain, '/');

        return $scheme.'://'.$host;
    }

    public function generateToken(): string
    {
        return 'slb-verify-'.Str::lower(Str::random(24));
    }

    /**
     * @return array{ok: bool, body?: string, message: string, status?: int}
     */
    private function fetchVerificationFile(Site $site): array
    {
        $candidates = $this->candidateFileUrls($site);

        foreach ($candidates as $url) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'SEOLinkBuildings-SiteVerify/1.0',
                        'Accept' => 'text/plain,*/*',
                    ])
                    ->withOptions(['allow_redirects' => true])
                    ->get($url);

                if ($response->status() === 404) {
                    continue;
                }

                if (! $response->successful()) {
                    return [
                        'ok' => false,
                        'message' => 'We could not read the verification file (HTTP '.$response->status().'). Make sure '.$url.' is publicly accessible.',
                        'status' => $response->status(),
                    ];
                }

                $body = (string) $response->body();
                // Reject obvious HTML error/ fort pages pretending to be the file.
                if (str_contains(strtolower($body), '<html') || str_contains(strtolower($body), '<!doctype')) {
                    continue;
                }

                return [
                    'ok' => true,
                    'body' => $body,
                    'message' => 'File found.',
                    'status' => $response->status(),
                ];
            } catch (\Throwable $e) {
                Log::info('Site file verification fetch failed', [
                    'site_id' => $site->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'ok' => false,
            'message' => 'Verification file not found. Upload '.self::FILE_NAME.' to your website root so it opens at '.$this->verificationFileUrl($site).' and try again.',
            'status' => 404,
        ];
    }

    /**
     * @return list<string>
     */
    private function candidateFileUrls(Site $site): array
    {
        $primary = $this->verificationFileUrl($site);
        $urls = [$primary];

        $parts = parse_url($primary);
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? 'https';
        if (! $host) {
            return array_values(array_unique($urls));
        }

        // Try www / non-www alternate.
        if (str_starts_with($host, 'www.')) {
            $altHost = substr($host, 4);
        } else {
            $altHost = 'www.'.$host;
        }
        $urls[] = $scheme.'://'.$altHost.'/'.self::FILE_NAME;

        // HTTP fallback for sites without HTTPS yet.
        if ($scheme === 'https') {
            $urls[] = 'http://'.$host.'/'.self::FILE_NAME;
            $urls[] = 'http://'.$altHost.'/'.self::FILE_NAME;
        }

        return array_values(array_unique($urls));
    }
}
