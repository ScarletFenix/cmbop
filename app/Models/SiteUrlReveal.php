<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUrlReveal extends Model
{
    use ToleratesUnparseableDates;

    public const SOURCE_CATALOG = 'catalog';

    public const SOURCE_CART = 'cart';

    public const SOURCE_ORDER = 'order';

    /** Clicked through to the site via the redirect, which discloses it too. */
    public const SOURCE_VISIT = 'visit';

    protected $fillable = [
        'user_id',
        'site_id',
        'source',
        'ip_address',
        'concealed_at',
    ];

    protected function casts(): array
    {
        return [
            'concealed_at' => 'datetime',
        ];
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
