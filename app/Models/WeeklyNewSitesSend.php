<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesSend extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'weekly_new_sites_sends';

    protected $fillable = [
        'recipient_id',
        'user_id',
        'site_id',
        'domain',
        'niche',
        'country_code',
        'status',
        'price_eur',
        'sent_at',
    ];

    protected $casts = [
        'price_eur' => 'decimal:2',
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

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(WeeklyNewSitesRecipient::class, 'recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
