<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCopyEvent extends Model
{
    use ToleratesUnparseableDates;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'site_id',
        'normalized_host',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'site_id' => 'integer',
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
