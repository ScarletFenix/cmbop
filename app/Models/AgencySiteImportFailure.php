<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AgencySiteImportFailure extends Model
{
    public const ROW_INDEX = 'asif_import_row_idx';

    public const LEGACY_ROW_INDEX = 'agency_site_import_failures_agency_site_import_id_row_number_index';

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

    /**
     * MySQL CREATE can succeed, then ALTER INDEX with the 65-char default
     * name fails (1059) and leaves this table behind. Re-runs must add the
     * short name without aborting later migrations.
     */
    public static function ensureRowIndex(): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $hasCompositeIndex = false;
        try {
            $indexNames = collect(Schema::getIndexes((new static)->getTable()))
                ->pluck('name')
                ->all();
            $hasCompositeIndex = in_array(self::ROW_INDEX, $indexNames, true)
                || in_array(self::LEGACY_ROW_INDEX, $indexNames, true);
        } catch (\Throwable) {
            // Locked-down hosts may not list indexes; try to add anyway.
        }

        if ($hasCompositeIndex) {
            return;
        }

        try {
            Schema::table((new static)->getTable(), function (Blueprint $table) {
                $table->index(['agency_site_import_id', 'row_number'], self::ROW_INDEX);
            });
        } catch (\Throwable) {
            // Already present under a driver-specific name, or not permitted.
        }
    }
}
