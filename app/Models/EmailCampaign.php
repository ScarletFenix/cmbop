<?php

namespace App\Models;

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

        $this->update([
            'sent_count' => $sent,
            'skipped_count' => $skipped,
        ]);

        $this->reconcileTerminalStatus();
    }

    /**
     * After queued mail later fails or is skipped, a campaign can sit on
     * `sent` with sent_count = 0. Only downgrade; a crashed job stays
     * `failed` even if leftover queued rows still deliver.
     */
    protected function reconcileTerminalStatus(): void
    {
        if ($this->status !== self::STATUS_SENT || $this->sent_count > 0) {
            return;
        }

        $open = $this->recipients()
            ->whereIn('status', [
                EmailCampaignRecipient::STATUS_PENDING,
                EmailCampaignRecipient::STATUS_QUEUED,
            ])
            ->exists();

        if (! $open) {
            $this->update(['status' => self::STATUS_FAILED]);
        }
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
}
