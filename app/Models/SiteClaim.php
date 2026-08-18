<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use App\Models\Concerns\ToleratesUnparseableDates;
use App\Services\Catalog\SiteUrlVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class SiteClaim extends Model
{
    use ToleratesMissingSchema, ToleratesUnparseableDates;

    protected $fillable = [
        'site_id',
        'claimer_id',
        'website_name',
        'website_url',
        'domain',
        'name_matches',
        'proof_message',
        'contact_email',
        'status',
        'admin_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'name_matches' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Listing name the claimer may see. Hide mode stays masked until the eye
     * (or hide expiry) — My Claims must not unmask a catalog row.
     */
    public function displayNameFor(?User $user, ?SiteUrlVisibility $visibility = null): string
    {
        $visibility ??= app(SiteUrlVisibility::class);

        if ($this->site) {
            return $visibility->nameFor($user, $this->site);
        }

        $raw = (string) ($this->website_name ?: 'Website');

        return $visibility->inHideMode($user)
            ? $visibility->maskName($raw)
            : $raw;
    }

    /**
     * Listing host the claimer may see. Same hide-mode rule as the catalog.
     */
    public function displayHostFor(?User $user, ?SiteUrlVisibility $visibility = null): string
    {
        $visibility ??= app(SiteUrlVisibility::class);

        if ($this->site) {
            return $visibility->hostFor($user, $this->site);
        }

        $raw = (string) ($this->website_url ?: $this->domain ?: '');

        return $visibility->inHideMode($user)
            ? $visibility->mask($raw)
            : (string) ($this->domain ?: $visibility->host($raw));
    }

    /**
     * @param  Collection<int, self>|iterable<self>  $claims
     */
    public static function applyCatalogIdentity(iterable $claims, ?User $user): void
    {
        $visibility = app(SiteUrlVisibility::class);
        $sites = collect($claims)->pluck('site')->filter();
        $visibility->warmFor($user, $sites);

        foreach ($claims as $claim) {
            if (! $claim instanceof self) {
                continue;
            }
            $claim->display_name = $claim->displayNameFor($user, $visibility);
            $claim->display_host = $claim->displayHostFor($user, $visibility);
        }
    }
}
