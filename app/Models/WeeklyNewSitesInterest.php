<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesInterest extends Model
{
    public const SOURCE_INFERRED = 'inferred';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'weekly_new_sites_interests';

    protected $fillable = [
        'user_id',
        'niche',
        'country_code',
        'source',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
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

    public function pairKey(): string
    {
        return strtolower(trim($this->niche)).'|'.strtolower(trim($this->country_code));
    }
}
