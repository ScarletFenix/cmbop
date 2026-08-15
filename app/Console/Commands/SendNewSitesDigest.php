<?php

namespace App\Console\Commands;

use App\Mail\NewSitesDigest;
use App\Models\Site;
use App\Services\AudienceInventoryService;
use App\Services\CartPricingService;
use App\Services\EmailNotificationService;
use App\Services\Reminders\NewSitesSelector;
use App\Services\Reminders\ReminderFatigueGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic "here is what is new" for advertisers who have bought before.
 *
 * On a per-recipient clock rather than one global blast: sends spread naturally
 * across days instead of spiking, a missed run self-corrects on the next one, and
 * someone who signed up yesterday is not dropped into the middle of a cycle.
 */
class SendNewSitesDigest extends Command
{
    protected $signature = 'sites:send-new-sites-digest
                            {--dry-run : Report what would be sent without sending}
                            {--limit=200 : Maximum recipients per run}';

    protected $description = 'Email advertisers a digest of new and discounted sites on a rolling cadence';

    public function handle(
        AudienceInventoryService $audiences,
        NewSitesSelector $selector,
        ReminderFatigueGuard $guard,
        EmailNotificationService $mailer,
    ): int {
        if (! Schema::hasColumn('users', 'new_sites_digest_sent_at')) {
            $this->warn('new_sites_digest_sent_at column missing — run migrations first.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $everyDays = max(1, (int) config('reminders.new_sites_digest.every_days', 15));
        $dueBefore = now()->subDays($everyDays);

        $recipients = $audiences->queryAdvertisersWithPaidOrders()
            ->whereNotNull('email')
            ->where(function ($q) use ($dueBefore) {
                $q->whereNull('new_sites_digest_sent_at')
                    ->orWhere('new_sites_digest_sent_at', '<=', $dueBefore);
            })
            ->orderBy('new_sites_digest_sent_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $this->info($recipients->count().' advertiser(s) due a digest');

        $sent = 0;
        $skippedThin = 0;

        foreach ($recipients as $advertiser) {
            try {
                if (! $guard->allows($advertiser)) {
                    $this->line('- skipped (daily cap) advertiser #'.$advertiser->id);

                    continue;
                }

                // Hide mode dual-masks catalog names. Do not email a fresh
                // name list, and do not advance the clock — they get the
                // digest after the window ends.
                if ($advertiser->inCatalogHideMode()) {
                    $this->line('- skipped (catalog hide mode) advertiser #'.$advertiser->id);

                    continue;
                }

                $sites = $selector->forUser($advertiser);

                if (! $selector->minimumMet($sites)) {
                    // A digest showing one site is worse than no digest. Leave
                    // the clock untouched so they are picked up next run.
                    $skippedThin++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("- would send {$sites->count()} site(s) to {$advertiser->email}");
                    $sent++;

                    continue;
                }

                $mailer->sendReminder($advertiser, new NewSitesDigest($advertiser, $sites->map(
                    fn (Site $site) => $this->row($site)
                )));

                $advertiser->forceFill(['new_sites_digest_sent_at' => now()])->save();
                $guard->record($advertiser);

                $sent++;
                $this->info("✓ Digest to {$advertiser->email} ({$sites->count()} sites)");
            } catch (\Throwable $e) {
                Log::error('New sites digest failed', [
                    'user_id' => $advertiser->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            '%sDigests sent: %d; skipped for too few sites: %d',
            $dryRun ? '[dry run] ' : '',
            $sent,
            $skippedThin
        ));

        return Command::SUCCESS;
    }

    /**
     * Advertiser-facing prices via CartPricingService (fee markup + payout floor).
     * Never show publisher base or a nominal % that oversells after the floor.
     *
     * @return array{
     *   site: Site,
     *   price: float,
     *   was: ?float,
     *   discount: ?float,
     *   is_new: bool
     * }
     */
    private function row(Site $site): array
    {
        $pricing = app(CartPricingService::class)->priceForAdvertiser($site);
        $list = (float) $pricing['list_total'];
        $pay = (float) $pricing['total'];
        $effectivePct = (float) ($pricing['discount_percent'] ?? 0);
        $hasOffer = $effectivePct > 0 && $pay < $list;
        $newWithin = now()->subDays(max(1, (int) config('reminders.new_sites_digest.new_within_days', 45)));

        return [
            'site' => $site,
            'price' => $pay,
            'was' => $hasOffer ? $list : null,
            'discount' => $hasOffer ? $effectivePct : null,
            'is_new' => (bool) ($site->created_at && $site->created_at->greaterThanOrEqualTo($newWithin)),
        ];
    }
}
