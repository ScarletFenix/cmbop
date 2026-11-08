<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesToken extends Model
{
    public const PURPOSE_UNSUB = 'unsub';

    public const PURPOSE_PREFERENCES = 'preferences';

    public const PURPOSE_CLICK = 'click';

    protected $table = 'weekly_new_sites_tokens';

    protected $fillable = [
        'token_hash',
        'user_id',
        'purpose',
        'payload',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
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

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
