<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesClick extends Model
{
    public const KIND_SITE = 'site';

    public const KIND_CATALOG = 'catalog';

    public const KIND_PREFERENCES = 'preferences';

    protected $table = 'weekly_new_sites_clicks';

    protected $fillable = [
        'user_id',
        'site_id',
        'recipient_id',
        'kind',
        'destination_path',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
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
}
