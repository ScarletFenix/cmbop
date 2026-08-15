<?php

namespace App\Services\SiteEnrichment\Providers;

use App\Contracts\SiteMetricsProvider;
use App\DTOs\SiteMetricsSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemrushMetricsProvider implements SiteMetricsProvider
{
    /**
     * ISO country → SEMrush Analytics database. Unknown countries fall back to us.
     *
     * @var array<string, string>
     */
    private const COUNTRY_DATABASES = [
        'us' => 'us',
        'gb' => 'uk',
        'uk' => 'uk',
        'ca' => 'ca',
        'au' => 'au',
        'de' => 'de',
        'fr' => 'fr',
        'es' => 'es',
        'it' => 'it',
        'nl' => 'nl',
        'be' => 'be',
        'ch' => 'ch',
        'at' => 'at',
        'pl' => 'pl',
        'se' => 'se',
        'no' => 'no',
        'dk' => 'dk',
        'fi' => 'fi',
        'ie' => 'ie',
        'pt' => 'pt',
        'br' => 'br',
        'mx' => 'mx',
        'ar' => 'ar',
        'cl' => 'cl',
        'co' => 'co',
        'in' => 'in',
        'jp' => 'jp',
        'kr' => 'kr',
        'sg' => 'sg',
        'hk' => 'hk',
        'tw' => 'tw',
        'za' => 'za',
        'ae' => 'ae',
        'il' => 'il',
        'tr' => 'tr',
        'ru' => 'ru',
        'cz' => 'cz',
        'ro' => 'ro',
        'hu' => 'hu',
        'gr' => 'gr',
        'nz' => 'nz',
    ];

    public function key(): string
    {
        return 'semrush';
    }

    public function isConfigured(): bool
    {
        return filled(config('site_enrichment.providers.semrush.api_key'));
    }

    /**
     * SEMrush regional database for a site country. Defaults to us when unknown.
     */
    public static function databaseForCountry(?string $country): string
    {
        $code = strtolower(trim((string) $country));

        return self::COUNTRY_DATABASES[$code] ?? 'us';
    }

    /**
     * Prefer the primary country column, then the first countries[] value.
     */
    public static function databaseForSite(Site $site): string
    {
        $code = $site->country;
        if (! filled($code) && is_array($site->countries)) {
            foreach ($site->countries as $candidate) {
                if (is_scalar($candidate) && filled($candidate)) {
                    $code = $candidate;
                    break;
                }
            }
        }

        return self::databaseForCountry(is_scalar($code) ? (string) $code : null);
    }

    public function fetch(Site $site): SiteMetricsSnapshot
    {
        $apiKey = (string) config('site_enrichment.providers.semrush.api_key');
        $base = rtrim((string) config('site_enrichment.providers.semrush.base_url'), '/');

        if (! $this->isConfigured()) {
            return SiteMetricsSnapshot::failure($this->key(), 'SEMrush API key is not configured.');
        }

        try {
            $response = Http::timeout(20)->get($base.'/', [
                'type' => 'domain_ranks',
                'key' => $apiKey,
                'export_columns' => 'Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
                'domain' => $site->domain,
                'database' => self::databaseForSite($site),
            ]);

            if (! $response->successful()) {
                Log::warning('SEMrush metrics fetch failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                ]);

                return SiteMetricsSnapshot::failure($this->key(), 'SEMrush API returned HTTP '.$response->status());
            }

            $body = trim((string) $response->body());
            $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
            $traffic = null;

            if (count($lines) >= 2) {
                $cols = str_getcsv($lines[1], ';');
                // Ot = Organic Traffic typically at index 3 for this export set
                if (isset($cols[3]) && is_numeric($cols[3])) {
                    $traffic = (int) $cols[3];
                }
            }

            return new SiteMetricsSnapshot(
                domainRating: null,
                domainAuthority: null,
                monthlyOrganicTraffic: $traffic,
                provider: $this->key(),
                raw: ['body' => mb_substr($body, 0, 500)],
                success: true,
            );
        } catch (\Throwable $e) {
            Log::error('SEMrush metrics exception', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return SiteMetricsSnapshot::failure($this->key(), $e->getMessage());
        }
    }
}
