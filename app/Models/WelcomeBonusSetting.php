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

        try {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        } catch (\Throwable) {
            return $default;
        }
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
            'enabled' => static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true),
        ], is_array($stored) ? $stored : []);
    }

    public static function isEnabled(): bool
    {
        $config = static::config();
        if (! array_key_exists('enabled', $config)) {
            return true;
        }

        // Unparseable / null stored flags fail closed so a corrupt row cannot
        // keep granting after an admin Disable. Missing table still fail-opens
        // via getValue() → enabled_default.
        return static::parseEnabledFlag($config['enabled'], false);
    }

    /**
     * Accept bools and common string/int flags. Non-scalars must not throw —
     * filter_var() TypeErrors would roll back every signup.
     */
    public static function parseEnabledFlag(mixed $raw, bool $default = false): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw) || is_string($raw)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
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
