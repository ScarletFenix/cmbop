<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Leftover Hostinger schema: missing tables/columns must 404 or skip writes,
 * not 500 the admin money screens.
 */
trait ToleratesMissingSchema
{
    public static function tableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasTableColumn(string $column): bool
    {
        try {
            return static::tableAvailable() && Schema::hasColumn((new static)->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function attributesThatExist(array $attributes): array
    {
        $kept = [];
        foreach ($attributes as $column => $value) {
            if (is_string($column) && $column !== '' && static::hasTableColumn($column)) {
                $kept[$column] = $value;
            }
        }

        return $kept;
    }

    /**
     * Explicit /{id} lookups must 404, not 500, when the table is gone.
     */
    public static function findAvailable(int|string $id): ?static
    {
        if (! static::tableAvailable()) {
            return null;
        }

        try {
            return static::query()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Implicit /{model} must 404, not 500, when the table is gone.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (! static::tableAvailable()) {
            return null;
        }

        try {
            return parent::resolveRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }
}
