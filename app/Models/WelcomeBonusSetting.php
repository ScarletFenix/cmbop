<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
        $read = static::readConfig(false);

        if ($read['state'] === 'missing') {
            return ['enabled' => $defaultOn];
        }

        if ($read['state'] === 'unreadable' || ! is_array($read['value'])) {
            return ['enabled' => false];
        }

        $stored = $read['value'];
        if (! array_key_exists('enabled', $stored)) {
            $stored['enabled'] = false;
        }

        return $stored;
    }

    public static function isEnabled(): bool
    {
        return static::readEnabled(false);
    }

    /**
     * Kill-switch read for the signup transaction. Locks the settings row so
     * an admin Disable cannot commit underneath recordClaim().
     */
    public static function isEnabledForGrant(): bool
    {
        return static::readEnabled(true);
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
        if (! Schema::hasTable((new static)->getTable())) {
            return;
        }

        $write = function () use ($enabled, $updatedBy): void {
            DB::transaction(function () use ($enabled, $updatedBy) {
                $row = static::query()->where('key', 'config')->lockForUpdate()->first();
                try {
                    $current = is_array($row?->value) ? $row->value : [];
                } catch (\Throwable) {
                    $current = [];
                }

                $current['enabled'] = $enabled;
                $current['updated_at'] = now()->toIso8601String();
                if ($updatedBy !== null) {
                    $current['updated_by'] = $updatedBy;
                }

                static::query()->updateOrCreate(['key' => 'config'], ['value' => $current]);
                Cache::forget('welcome_bonus_setting:config');
            });
        };

        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            // First grant may insert the settings row in the same window.
            $write();
        }
    }

    public static function clearCache(): void
    {
        Cache::forget('welcome_bonus_setting:config');
    }

    private static function readEnabled(bool $lock): bool
    {
        $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
        $read = static::readConfig($lock);

        // Missing table / never configured: fail-open so Hostinger drift
        // cannot block the €20 grant. A present row that cannot be trusted
        // fails closed so Disable cannot be undone by corrupt JSON.
        if ($read['state'] === 'missing') {
            return $defaultOn;
        }

        if ($read['state'] === 'unreadable') {
            return false;
        }

        $stored = $read['value'];
        if (! is_array($stored) || ! array_key_exists('enabled', $stored)) {
            return false;
        }

        return static::parseEnabledFlag($stored['enabled'], false);
    }

    /**
     * @return array{state: 'missing'|'unreadable'|'present', value: mixed}
     */
    private static function readConfig(bool $lock): array
    {
        try {
            if (! Schema::hasTable((new static)->getTable())) {
                return ['state' => 'missing', 'value' => null];
            }

            $query = static::query()->where('key', 'config');
            if ($lock) {
                $query->lockForUpdate();
            }
            $row = $query->first();
            if ($row === null && $lock) {
                $defaultOn = static::parseEnabledFlag(config('welcome_bonus.enabled_default', true), true);
                try {
                    static::query()->create([
                        'key' => 'config',
                        'value' => ['enabled' => $defaultOn],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another grant or Disable created the row first.
                }
                $row = static::query()->where('key', 'config')->lockForUpdate()->first();
            }
            if ($row === null) {
                return ['state' => 'missing', 'value' => null];
            }

            return ['state' => 'present', 'value' => $row->value];
        } catch (\Throwable) {
            return ['state' => 'unreadable', 'value' => null];
        }
    }
}
