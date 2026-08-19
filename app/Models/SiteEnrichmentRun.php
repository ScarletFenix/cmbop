<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class SiteEnrichmentRun extends Model
{
    use ToleratesUnparseableDates;

    public const ATTENTION_STATUSES = ['failed', 'partial'];

    public const STUCK_RUNNING_MINUTES = 15;

    protected $fillable = [
        'site_id',
        'type',
        'provider',
        'status',
        'payload',
        'error',
        'triggered_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Latest run id per site (any status).
     */
    public static function latestIdPerSiteQuery(): Builder
    {
        return static::query()
            ->selectRaw('MAX(id)')
            ->whereNotNull('site_id')
            ->groupBy('site_id');
    }

    /**
     * Latest screenshot run id per site.
     */
    public static function latestScreenshotIdPerSiteQuery(): Builder
    {
        return static::query()
            ->selectRaw('MAX(id)')
            ->where('type', 'screenshot')
            ->whereNotNull('site_id')
            ->groupBy('site_id');
    }

    /**
     * Sites whose latest screenshot run stored a placeholder (not a real preview).
     *
     * @return list<int>
     */
    public static function placeholderScreenshotSiteIds(): array
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return [];
        }

        try {
            return static::query()
                ->whereIn('id', static::latestScreenshotIdPerSiteQuery())
                ->where('status', 'partial')
                ->get(['site_id', 'payload'])
                ->filter(fn (self $run) => (bool) data_get($run->payload, 'used_placeholder'))
                ->pluck('site_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Latest run per existing site, limited to failed / partial / stuck running.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsAttention(Builder $query, ?string $status = null, ?string $type = null): Builder
    {
        $query->whereIn('id', static::latestIdPerSiteQuery())
            ->whereHas('site');

        $stuckBefore = now()->subMinutes(self::STUCK_RUNNING_MINUTES);

        if (in_array($status, self::ATTENTION_STATUSES, true)) {
            $query->where('status', $status);
        } else {
            $query->where(function (Builder $q) use ($stuckBefore) {
                $q->whereIn('status', self::ATTENTION_STATUSES)
                    ->orWhere(function (Builder $q) use ($stuckBefore) {
                        $q->where('status', 'running')
                            ->where(function (Builder $q) use ($stuckBefore) {
                                $q->where('started_at', '<', $stuckBefore)
                                    ->orWhere(function (Builder $q) use ($stuckBefore) {
                                        $q->whereNull('started_at')
                                            ->where('created_at', '<', $stuckBefore);
                                    });
                            });
                    });
            });
        }

        if (in_array($type, ['metrics', 'screenshot'], true)) {
            $query->where('type', $type);
        }

        return $query;
    }
}
