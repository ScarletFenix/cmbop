<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Periodic "here is what is new in the catalog" for advertisers who have bought
 * before. Discounted and recently listed sites, ranked, with the ones they have
 * already seen removed.
 *
 * @phpstan-type DigestRow array{site: \App\Models\Site, price: float, was: ?float, discount: ?float, is_new: bool}
 */
class NewSitesDigest extends PlatformMailable
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public User $advertiser,
        public Collection $rows,
    ) {
        parent::__construct();

        $this->notificationType = 'new_sites_digest';
        $this->recipientUser = $advertiser;
        // Per-cycle key: the digest recurs, so it must not be deduped forever.
        $this->dedupeKey = 'new_sites_digest:'.$advertiser->id.':'.now()->format('Y-m-d');
    }

    public function build()
    {
        $discounted = $this->rows->where('discount', '>', 0)->count();

        return $this->subject($this->subjectLine($discounted))
            ->markdown('emails.advertiser.new-sites-digest', [
                'firstName' => $this->firstName($this->advertiser),
                'rows' => $this->rows,
                'discounted' => $discounted,
                'catalogUrl' => $this->publicRoute('advertiser.catalog'),
                'brand' => $this->brand(),
            ]);
    }

    private function subjectLine(int $discounted): string
    {
        $count = $this->rows->count();

        if ($discounted > 0) {
            return $discounted === 1
                ? $count.' new sites in the catalog (1 on offer)'
                : $count.' new sites in the catalog ('.$discounted.' on offer)';
        }

        return $count.' new sites just added to the catalog';
    }
}
