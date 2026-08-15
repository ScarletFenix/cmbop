<?php

namespace App\Models;

use App\Jobs\SendEmailCampaignJob;
use App\Services\AudienceInventoryService;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EmailCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'name',
        'subject',
        'body_html',
        'audience',
        'selected_user_ids',
        'cta_label',
        'cta_url',
        'recipients_count',
        'sent_count',
        'skipped_count',
        'status',
        'respect_preferences',
        'created_by',
        'sent_at',
    ];

    protected $casts = [
        'selected_user_ids' => 'array',
        'respect_preferences' => 'boolean',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'skipped_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    public function recountRecipientTotals(): void
    {
        $sent = $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_QUEUED,
                EmailCampaignRecipient::STATUS_DELIVERED,
            ])
            ->count();
        $skipped = $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_SKIPPED,
                EmailCampaignRecipient::STATUS_FAILED,
            ])
            ->count();

        $payload = [
            'sent_count' => $sent,
            'skipped_count' => $skipped,
        ];

        // queued counts toward progress, but a terminal campaign is only
        // "sent" after at least one real delivery. Otherwise a retry or a
        // lost mail job would flip failed → sent while nothing went out.
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_FAILED], true)) {
            $delivered = $this->recipients()
                ->where('status', EmailCampaignRecipient::STATUS_DELIVERED)
                ->count();
            $queued = $this->recipients()
                ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                ->count();

            if ($delivered > 0) {
                $payload['status'] = self::STATUS_SENT;
            } elseif ($queued === 0) {
                $payload['status'] = self::STATUS_FAILED;
            }
        }

        $this->update($payload);
    }

    public static function labelForAudience(?string $audience): string
    {
        return AudienceInventoryService::label($audience);
    }

    public function audienceLabel(): string
    {
        return self::labelForAudience($this->audience);
    }

    /**
     * Re-queue campaigns whose worker died (OOM, deploy, drain timeout)
     * instead of leaving them stuck on queued/sending.
     */
    public static function recoverStalled(int $staleMinutes = 2): int
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $lock = null;
        try {
            $store = Cache::store()->getStore();
            if ($store instanceof LockProvider) {
                $lock = Cache::store()->lock('email-campaigns:recover-stalled', 15);
                if (! $lock->get()) {
                    return 0;
                }
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            return self::recoverStalledLocked($staleMinutes);
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable) {
            }
        }
    }

    protected static function recoverStalledLocked(int $staleMinutes): int
    {
        $stale = now()->subMinutes(max(1, $staleMinutes));
        $dispatched = 0;

        $ids = static::query()
            ->where(function ($query) use ($stale) {
                $query->where(function ($queued) use ($stale) {
                    $queued->where('status', self::STATUS_QUEUED)
                        ->where('updated_at', '<=', $stale);
                })->orWhere(function ($sending) use ($stale) {
                    $sending->where('status', self::STATUS_SENDING)
                        ->where('updated_at', '<=', $stale);
                });
            })
            ->pluck('id');

        foreach ($ids as $id) {
            $campaign = static::query()->find($id);
            if (! $campaign) {
                continue;
            }

            if ($campaign->status === self::STATUS_SENDING
                && ! $campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_PENDING)
                    ->exists()) {
                if ($campaign->recipients()
                    ->where('status', EmailCampaignRecipient::STATUS_QUEUED)
                    ->exists()) {
                    // Mail is still in flight (or the job was lost). Do not
                    // pretend the campaign sent.
                    continue;
                }

                $campaign->recountRecipientTotals();
                $campaign->refresh();
                $campaign->update([
                    'status' => $campaign->sent_count > 0
                        ? self::STATUS_SENT
                        : self::STATUS_FAILED,
                    'sent_at' => $campaign->sent_at ?? now(),
                ]);

                continue;
            }

            try {
                SendEmailCampaignJob::dispatch((int) $id);
                $dispatched++;
            } catch (\Throwable $e) {
                Log::warning('Stalled campaign re-queue failed', [
                    'campaign_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatched;
    }
}
