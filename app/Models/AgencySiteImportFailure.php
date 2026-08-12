<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencySiteImportFailure extends Model
{
    protected $fillable = [
        'agency_site_import_id',
        'row_number',
        'site_url',
        'site_name',
        'errors',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'errors' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(AgencySiteImport::class, 'agency_site_import_id');
    }
}
