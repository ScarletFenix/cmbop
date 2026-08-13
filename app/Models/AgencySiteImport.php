<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencySiteImport extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_CLOSED = 'closed';

    public const MAX_ROWS = 200;

    protected $fillable = [
        'publisher_id',
        'status',
        'original_filename',
        'dry_run',
        'processed_count',
        'created_count',
        'failed_count',
        'would_create_count',
        'admin_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'processed_count' => 'integer',
        'created_count' => 'integer',
        'failed_count' => 'integer',
        'would_create_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'agency_site_import_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(AgencySiteImportFailure::class);
    }

    public function isOpenForReview(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_PARTIAL,
        ], true);
    }

    public function pendingReviewSitesCount(): int
    {
        return $this->sites()
            ->where(function ($q) {
                $q->where('verified', false)->orWhere('active', false);
            })
            ->count();
    }

    public function refreshReviewStatus(): void
    {
        if (in_array($this->status, [self::STATUS_FAILED, self::STATUS_CLOSED, self::STATUS_PROCESSING], true)) {
            return;
        }

        if ($this->dry_run) {
            return;
        }

        $pending = $this->pendingReviewSitesCount();
        if ($pending === 0 && $this->created_count > 0) {
            $this->forceFill([
                'status' => self::STATUS_REVIEWED,
                'reviewed_at' => $this->reviewed_at ?? now(),
            ])->save();
        }
    }

    public function finalizeStatus(): void
    {
        if ($this->dry_run) {
            // Dry runs are audit-only; never enter the admin review queue.
            $this->forceFill(['status' => self::STATUS_CLOSED])->save();

            return;
        }

        if ($this->created_count <= 0) {
            $status = self::STATUS_FAILED;
        } elseif ($this->failed_count > 0) {
            $status = self::STATUS_PARTIAL;
        } else {
            $status = self::STATUS_SUBMITTED;
        }

        $this->forceFill(['status' => $status])->save();
    }
}
