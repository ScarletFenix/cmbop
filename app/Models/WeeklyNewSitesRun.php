<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'weekly_new_sites_runs';

    protected $fillable = [
        'week_key',
        'dry_run',
        'status',
        'matched_advertisers',
        'queued',
        'skipped_thin',
        'skipped_permission',
        'skipped_other',
        'notes',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WeeklyNewSitesRecipient::class, 'run_id');
    }
}
