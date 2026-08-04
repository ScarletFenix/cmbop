<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUrlReveal extends Model
{
    public const SOURCE_CATALOG = 'catalog';

    public const SOURCE_CART = 'cart';

    public const SOURCE_ORDER = 'order';

    protected $fillable = [
        'user_id',
        'site_id',
        'source',
        'ip_address',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
