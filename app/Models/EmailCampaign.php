<?php

namespace App\Models;

use App\Jobs\SendEmailCampaignJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

        // Finalize treats queued mail as sent. After a retry or a late
        // failure, keep the terminal status honest against those totals.
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_FAILED], true)) {
            $payload['status'] = $sent > 0 ? self::STATUS_SENT : self::STATUS_FAILED;
        }

        $this->update($payload);
    }

    public static function labelForAudience(?string $audience): string
    {
        return match ($audience) {
            'advertisers' => 'Advertisers',
            'publishers' => 'Publishers',
            'both' => 'Advertisers + Publishers',
            'advertisers_no_orders', 'advertisers_never_checked_out' => 'Advertisers (never checked out)',
            'advertisers_no_paid_orders' => 'Advertisers (no paid orders)',
            'publishers_no_sites' => 'Publishers (no sites)',
            'advertisers_never_deposited' => 'Advertisers (never deposited)',
            'selected' => 'Selected users',
            default => ucfirst((string) $audience),
        };
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

            SendEmailCampaignJob::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }
}
