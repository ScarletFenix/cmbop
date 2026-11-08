<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesRecipient extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $table = 'weekly_new_sites_recipients';

    protected $fillable = [
        'run_id',
        'user_id',
        'week_key',
        'status',
        'permission_basis',
        'site_count',
        'match_pairs',
        'dedupe_key',
        'skip_reason',
        'queued_at',
        'sent_at',
    ];

    protected $casts = [
        'match_pairs' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WeeklyNewSitesRun::class, 'run_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(WeeklyNewSitesSend::class, 'recipient_id');
    }
}
