<?php

namespace App\Services\WeeklyNewSites;

use App\Mail\WeeklyNewSitesDigestMail;
use App\Models\EmailNotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\WeeklyNewSitesInterest;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesRun;
use App\Models\WeeklyNewSitesSend;
use App\Services\EmailNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DigestDispatcher
{
    public function __construct(
        private Watermark $watermark,
        private PermissionGate $permission,
        private MatchSelector $matches,
        private CardPresenter $presenter,
        private EmailNotificationService $mailer,
    ) {}

    public function weekKey(?Carbon $at = null): string
    {
        $tz = (string) config('weekly_new_sites_digest.timezone', 'Asia/Karachi');

        return ($at ?? now())->copy()->timezone($tz)->format('o-\WW');
    }

    public function tablesReady(): bool
    {
        return WeeklyNewSitesRun::tableReady()
            && WeeklyNewSitesRecipient::tableReady()
            && WeeklyNewSitesSend::tableReady()
            && Schema::hasColumn('sites', 'first_catalogued_at');
    }

    /**
     * @return array{
     *   week_key: string,
     *   dry_run: bool,
     *   queued: int,
     *   skipped_thin: int,
     *   skipped_permission: int,
     *   skipped_other: int,
     *   matched_advertisers: int,
     *   notes: string
     * }
     */
    public function run(bool $dryRun = false, int $limit = 500, ?int $onlyUserId = null): array
    {
        $weekKey = $this->weekKey();
        $empty = [
            'week_key' => $weekKey,
            'dry_run' => $dryRun,
            'queued' => 0,
            'skipped_thin' => 0,
            'skipped_permission' => 0,
            'skipped_other' => 0,
            'matched_advertisers' => 0,
            'notes' => '',
        ];

        if (! $this->tablesReady()) {
            $empty['notes'] = 'tables_missing';

            return $empty;
        }

        $this->watermark->establish();

        if (! EmailNotificationSetting::isEnabled(PermissionGate::TYPE)) {
            $empty['notes'] = 'admin_disabled';

            return $empty;
        }

        $run = WeeklyNewSitesRun::query()->create([
            'week_key' => $weekKey,
            'dry_run' => $dryRun,
            'status' => WeeklyNewSitesRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $queued = 0;
        $thin = 0;
        $denied = 0;
        $other = 0;
        $matched = 0;

        try {
            $advertisers = $this->advertiserQuery($onlyUserId)
                ->limit(max(1, $limit))
                ->get();

            foreach ($advertisers as $advertiser) {
                $result = $this->processAdvertiser($advertiser, $weekKey, $run, $dryRun);
                match ($result) {
                    'queued' => $queued++,
                    'thin' => $thin++,
                    'permission' => $denied++,
                    'duplicate' => $other++,
                    default => $other++,
                };
                if (in_array($result, ['queued', 'thin'], true)) {
                    $matched++;
                }
            }

            $run->fill([
                'status' => WeeklyNewSitesRun::STATUS_COMPLETED,
                'matched_advertisers' => $matched,
                'queued' => $queued,
                'skipped_thin' => $thin,
                'skipped_permission' => $denied,
                'skipped_other' => $other,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->fill([
                'status' => WeeklyNewSitesRun::STATUS_FAILED,
                'notes' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
            Log::error('Weekly new sites digest run failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return [
            'week_key' => $weekKey,
            'dry_run' => $dryRun,
            'queued' => $queued,
            'skipped_thin' => $thin,
            'skipped_permission' => $denied,
            'skipped_other' => $other,
            'matched_advertisers' => $matched,
            'notes' => '',
        ];
    }

    public function processAdvertiser(User $advertiser, string $weekKey, ?WeeklyNewSitesRun $run, bool $dryRun): string
    {
        $lock = Cache::lock('weekly_new_sites:'.$advertiser->id.':'.$weekKey, 30);
        if (! $lock->get()) {
            return 'duplicate';
        }

        try {
            return $this->processAdvertiserLocked($advertiser, $weekKey, $run, $dryRun);
        } finally {
            optional($lock)->release();
        }
    }

    private function processAdvertiserLocked(User $advertiser, string $weekKey, ?WeeklyNewSitesRun $run, bool $dryRun): string
    {
        $permission = $this->permission->inspect($advertiser);
        if (! $permission['allowed']) {
            return 'permission';
        }

        if ($advertiser->inCatalogHideMode()) {
            return 'other';
        }

        $min = max(1, (int) config('weekly_new_sites_digest.min_sites', 2));
        $max = max($min, (int) config('weekly_new_sites_digest.max_sites', 5));

        $cards = $this->matches->matches($advertiser, $max);
        if ($cards->isEmpty()) {
            return 'other';
        }
        if ($cards->count() < $min) {
            return 'thin';
        }

        if ($dryRun) {
            return 'queued';
        }

        $recipient = $this->claimRecipient($advertiser, $weekKey, $run, $permission['basis'], $cards);
        if (! $recipient) {
            return 'duplicate';
        }

        $cards = $this->matches->recheck($advertiser, $cards);
        if ($cards->count() < $min) {
            $this->cancelRecipient($recipient, 'below_min_at_recheck');

            return 'thin';
        }

        $cards = $cards->take($max)->values();
        $this->claimSends($recipient, $advertiser, $cards);

        $pairs = $this->matches->distinctPairs($cards);
        $recipient->forceFill([
            'site_count' => $cards->count(),
            'match_pairs' => $pairs,
        ])->save();

        $decorated = $this->presenter->decorate($advertiser, $cards, $recipient);
        $browse = $this->presenter->browseLinks($advertiser, $pairs, $recipient);
        $mail = new WeeklyNewSitesDigestMail(
            $advertiser,
            $decorated,
            $browse,
            $weekKey,
            (int) $recipient->id,
            (string) $permission['basis'],
        );

        $handed = $this->mailer->sendReminder($advertiser, $mail);
        if (! $handed) {
            $this->cancelRecipient($recipient, 'mailer_rejected');

            return 'permission';
        }

        return 'queued';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function claimRecipient(
        User $advertiser,
        string $weekKey,
        ?WeeklyNewSitesRun $run,
        ?string $basis,
        Collection $cards,
    ): ?WeeklyNewSitesRecipient {
        $dedupe = 'weekly_new_sites:'.$advertiser->id.':'.$weekKey;
        $now = now();

        try {
            $inserted = WeeklyNewSitesRecipient::query()->insertOrIgnore([
                'run_id' => $run?->id,
                'user_id' => $advertiser->id,
                'week_key' => $weekKey,
                'status' => WeeklyNewSitesRecipient::STATUS_QUEUED,
                'permission_basis' => $basis,
                'site_count' => $cards->count(),
                'match_pairs' => json_encode($this->matches->distinctPairs($cards)),
                'dedupe_key' => $dedupe,
                'queued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Weekly new sites recipient claim failed', [
                'user_id' => $advertiser->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ((int) $inserted < 1) {
            return null;
        }

        return WeeklyNewSitesRecipient::query()
            ->where('user_id', $advertiser->id)
            ->where('week_key', $weekKey)
            ->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cards
     */
    private function claimSends(WeeklyNewSitesRecipient $recipient, User $user, Collection $cards): void
    {
        $now = now();
        foreach ($cards as $card) {
            try {
                WeeklyNewSitesSend::query()->insertOrIgnore([
                    'recipient_id' => $recipient->id,
                    'user_id' => $user->id,
                    'site_id' => (int) $card['site_id'],
                    'domain' => (string) ($card['domain'] ?? ''),
                    'niche' => (string) ($card['niche'] ?? ''),
                    'country_code' => (string) ($card['country_code'] ?? ''),
                    'status' => WeeklyNewSitesSend::STATUS_QUEUED,
                    'price_eur' => $card['price'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Weekly new sites send claim failed', [
                    'user_id' => $user->id,
                    'site_id' => $card['site_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function markDelivered(WeeklyNewSitesRecipient $recipient): void
    {
        $recipient->forceFill([
            'status' => WeeklyNewSitesRecipient::STATUS_SENT,
            'sent_at' => $recipient->sent_at ?? now(),
            'skip_reason' => null,
        ])->save();

        WeeklyNewSitesSend::query()
            ->where('recipient_id', $recipient->id)
            ->where('status', WeeklyNewSitesSend::STATUS_QUEUED)
            ->update([
                'status' => WeeklyNewSitesSend::STATUS_SENT,
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function cancelRecipient(WeeklyNewSitesRecipient $recipient, string $reason): void
    {
        $recipient->forceFill([
            'status' => WeeklyNewSitesRecipient::STATUS_CANCELLED,
            'skip_reason' => $reason,
        ])->save();

        // Release claimed sites so they carry forward to a later week.
        WeeklyNewSitesSend::query()
            ->where('recipient_id', $recipient->id)
            ->where('status', WeeklyNewSitesSend::STATUS_QUEUED)
            ->delete();
    }

    /**
     * @return Builder<User>
     */
    private function advertiserQuery(?int $onlyUserId)
    {
        $query = User::query()->whereNotNull('email');

        $role = Role::query()->where('name', 'advertiser')->first();
        if ($role) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id));
        }

        if ($onlyUserId) {
            $query->where('id', $onlyUserId);
        } else {
            $query->where(function ($outer) {
                $outer->whereHas('orders', function ($q) {
                    $q->whereIn('payment_status', ['paid', 'completed'])
                        ->where(function ($status) {
                            $status->whereNull('status')
                                ->orWhereNotIn('status', ['cancelled', 'canceled']);
                        });
                });
                if (WeeklyNewSitesInterest::tableReady()) {
                    $outer->orWhereIn('id', WeeklyNewSitesInterest::query()
                        ->where('enabled', true)
                        ->select('user_id'));
                }
            });
        }

        return $query->orderBy('id');
    }
}
