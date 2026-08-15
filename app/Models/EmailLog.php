<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'mailable',
        'template_key',
        'notification_type',
        'dedupe_key',
        'audience',
        'to_email',
        'to_name',
        'from_email',
        'subject',
        'status',
        'error',
        'attempts',
        'meta',
        'sent_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Open rows for a dedupe key, newest first. A retry can leave more than
     * one pending/failed row; callers that close a send must update all of them.
     *
     * @return Collection<int, self>
     */
    public static function openByDedupe(?string $key)
    {
        if (! filled($key)) {
            return static::query()->whereRaw('0 = 1')->get();
        }

        return static::query()
            ->where('dedupe_key', $key)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->orderByDesc('id')
            ->get();
    }

    public static function findOpenByDedupe(?string $key): ?self
    {
        return static::openByDedupe($key)->first();
    }

    /**
     * A leftover failed/pending row after a real SMTP success. Campaign
     * dedupe keys are one-shot; retrying them is a second blast.
     */
    public static function latestDeliveredByDedupe(?string $key): ?self
    {
        if (! filled($key)) {
            return null;
        }

        return static::query()
            ->where('dedupe_key', $key)
            ->where('status', self::STATUS_DELIVERED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{sent_today: int, pending: int, failed: int, delivered: int}
     */
    public static function dashboardKpis(): array
    {
        $today = now()->toDateString();
        $row = static::query()
            ->toBase()
            ->selectRaw(
                'SUM(CASE WHEN date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as sent_today,
                 SUM(CASE WHEN status = ? AND date(coalesce(sent_at, created_at)) = ? THEN 1 ELSE 0 END) as delivered_today,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count',
                [$today, self::STATUS_DELIVERED, $today, self::STATUS_PENDING, self::STATUS_FAILED]
            )
            ->first();

        return [
            'sent_today' => (int) ($row->sent_today ?? 0),
            'pending' => (int) ($row->pending_count ?? 0),
            'failed' => (int) ($row->failed_count ?? 0),
            'delivered' => (int) ($row->delivered_today ?? 0),
        ];
    }
}
