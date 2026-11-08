<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WeeklyNewSitesSetting extends Model
{
    protected $table = 'weekly_new_sites_settings';

    protected $fillable = ['key', 'value'];

    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        if (! static::tableReady()) {
            return $default;
        }

        $row = static::query()->where('key', $key)->first();

        return $row && is_string($row->value) && $row->value !== '' ? $row->value : $default;
    }

    public static function putValue(string $key, string $value): void
    {
        if (! static::tableReady()) {
            return;
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
