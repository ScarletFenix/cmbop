<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class WelcomeBonusSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return $default;
        }

        return Cache::remember('welcome_bonus_setting:'.$key, 60, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function setValue(string $key, mixed $value): void
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('welcome_bonus_setting:'.$key);
    }

    public static function config(): array
    {
        $stored = static::getValue('config', []) ?: [];

        return array_merge([
            'enabled' => (bool) config('welcome_bonus.enabled_default', true),
        ], is_array($stored) ? $stored : []);
    }

    public static function isEnabled(): bool
    {
        return (bool) (static::config()['enabled'] ?? true);
    }

    public static function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        $current = static::getValue('config', []) ?: [];
        if (! is_array($current)) {
            $current = [];
        }

        $current['enabled'] = $enabled;
        $current['updated_at'] = now()->toIso8601String();
        if ($updatedBy !== null) {
            $current['updated_by'] = $updatedBy;
        }

        static::setValue('config', $current);
    }

    public static function clearCache(): void
    {
        Cache::forget('welcome_bonus_setting:config');
    }
}
