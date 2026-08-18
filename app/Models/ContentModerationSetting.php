<?php

namespace App\Models;

use App\Models\Concerns\ToleratesMissingSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ContentModerationSetting extends Model
{
    use ToleratesMissingSchema;

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! static::tableAvailable()) {
            return $default;
        }

        try {
            return Cache::remember('content_moderation_setting:'.$key, 60, function () use ($key, $default) {
                $row = static::query()->where('key', $key)->first();

                return $row?->value ?? $default;
            });
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function setValue(string $key, mixed $value): void
    {
        if (! static::tableAvailable()) {
            throw new \RuntimeException('Content moderation settings are unavailable on this database.');
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('content_moderation_setting:'.$key);
        Cache::forget('content_moderation_effective_config');
    }

    public static function clearCache(): void
    {
        Cache::forget('content_moderation_effective_config');
        if (! static::tableAvailable()) {
            return;
        }

        try {
            foreach (static::query()->pluck('key') as $key) {
                Cache::forget('content_moderation_setting:'.$key);
            }
        } catch (\Throwable) {
            // Leftover Hostinger: cache keys expire on their own.
        }
    }
}
