<?php

namespace App\Services\SiteEnrichment;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScreenshotCaptureService
{
    public function __construct(
        private readonly ImageOptimizationService $images,
    ) {}

    /**
     * Capture homepage screenshot, optimize to WebP, store publicly.
     *
     * @return array{path: ?string, thumb_path: ?string, success: bool, error: ?string, used_placeholder: bool}
     */
    public function capture(Site $site): array
    {
        $url = $this->homepageUrl($site);
        $directory = trim((string) config('site_enrichment.screenshots.storage_path', 'site-screenshots'), '/');
        $basename = 'site-'.$site->id.'-'.now()->format('YmdHis');

        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');
        $binary = $this->fetchScreenshotBinary($url);

        if ($binary === null) {
            // none/placeholder are intentional no-ops — do not warn (tests + opt-out envs).
            if (! in_array($provider, ['none', 'placeholder'], true)) {
                Log::warning('Screenshot capture failed; using placeholder', [
                    'site_id' => $site->id,
                    'url' => $url,
                    'provider' => $provider,
                ]);
            }

            return $this->failureResult(
                $site,
                $directory,
                $basename,
                'Screenshot provider failed; placeholder stored.',
                'Screenshot capture failed and placeholder could not be generated.'
            );
        }

        $stored = $this->images->storeOptimizedWebp($binary, $directory, $basename);
        if ($stored === null) {
            return $this->failureResult(
                $site,
                $directory,
                $basename,
                'Image optimization failed.',
                'Image optimization failed.'
            );
        }

        // Only remove previous files after a successful new save — a failed
        // refresh must not wipe a good catalog preview on durable media.
        $this->deleteOldFiles($site, [$stored['path'], $stored['thumb_path']]);

        return [
            'path' => $stored['path'],
            'thumb_path' => $stored['thumb_path'],
            'success' => true,
            'error' => null,
            'used_placeholder' => false,
        ];
    }

    public function homepageUrl(Site $site): string
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $url = 'https://'.ltrim((string) $site->domain, '/');
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /**
     * On failure: keep any existing good screenshot. Only invent a placeholder
     * when the site had no prior capture (first fill / empty listing).
     *
     * @return array{path: ?string, thumb_path: ?string, success: bool, error: ?string, used_placeholder: bool}
     */
    private function failureResult(
        Site $site,
        string $directory,
        string $basename,
        string $placeholderError,
        string $noPlaceholderError,
    ): array {
        $previousPath = $site->screenshot_path;
        $previousThumb = $site->screenshot_thumb_path;
        if ($previousPath || $previousThumb) {
            return [
                'path' => $previousPath,
                'thumb_path' => $previousThumb,
                'success' => false,
                'error' => 'Screenshot refresh failed; previous preview kept.',
                'used_placeholder' => false,
            ];
        }

        $placeholder = $this->images->storePlaceholder($directory, $basename, 'Preview unavailable');
        if ($placeholder === null) {
            return [
                'path' => null,
                'thumb_path' => null,
                'success' => false,
                'error' => $noPlaceholderError,
                'used_placeholder' => true,
            ];
        }

        return [
            'path' => $placeholder['path'],
            'thumb_path' => $placeholder['thumb_path'],
            'success' => false,
            'error' => $placeholderError,
            'used_placeholder' => true,
        ];
    }

    private function fetchScreenshotBinary(string $url): ?string
    {
        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');

        try {
            return match ($provider) {
                'screenshotone' => $this->viaScreenshotOne($url),
                'url_api' => $this->viaUrlApi($url),
                'thum_io' => $this->viaThumIo($url),
                'placeholder', 'none' => null,
                default => $this->viaThumIo($url),
            };
        } catch (\Throwable $e) {
            Log::error('Screenshot provider exception', [
                'provider' => $provider,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function viaThumIo(string $url): ?string
    {
        // Free/public thumbnail service — replaceable via config.
        // Force a desktop viewport (not a phone-sized capture) then crop to the
        // configured desktop frame so catalog/admin previews read as 16:10.
        $width = max(1024, (int) config('site_enrichment.screenshots.width', 1280));
        $height = max(640, (int) config('site_enrichment.screenshots.height', 800));
        $endpoint = sprintf(
            'https://image.thum.io/get/width/%d/crop/%d/viewportWidth/%d/noanimate/%s',
            $width,
            $height,
            $width,
            rawurlencode($url)
        );
        $response = Http::timeout(45)->get($endpoint);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    private function viaUrlApi(string $url): ?string
    {
        $template = (string) config('site_enrichment.screenshots.api_url');
        if ($template === '' || ! str_contains($template, '{url}')) {
            return null;
        }

        $endpoint = str_replace('{url}', rawurlencode($url), $template);
        $response = Http::timeout((int) config('site_enrichment.screenshots.timeout', 45))->get($endpoint);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    private function viaScreenshotOne(string $url): ?string
    {
        $key = (string) config('site_enrichment.screenshots.screenshotone_access_key');
        if ($key === '') {
            return null;
        }

        $response = Http::timeout(45)->get('https://api.screenshotone.com/take', [
            'access_key' => $key,
            'url' => $url,
            'viewport_width' => (int) config('site_enrichment.screenshots.width', 1280),
            'viewport_height' => (int) config('site_enrichment.screenshots.height', 800),
            'format' => 'png',
            'block_ads' => true,
            'block_cookie_banners' => true,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        return is_string($body) && strlen($body) > 500 ? $body : null;
    }

    /**
     * @param  list<string|null>  $keep
     */
    private function deleteOldFiles(Site $site, array $keep = []): void
    {
        $disk = Storage::disk('public');
        $keep = array_values(array_filter($keep));
        foreach ([$site->screenshot_path, $site->screenshot_thumb_path] as $path) {
            if (! $path || in_array($path, $keep, true)) {
                continue;
            }
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
