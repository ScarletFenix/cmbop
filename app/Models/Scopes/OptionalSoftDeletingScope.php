<?php

namespace App\Models\Scopes;

use App\Models\Concerns\ToleratesUnparseableDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete scope that no-ops when deleted_at has not been migrated yet.
 * Hostinger heals schema after the first response, so the first page view
 * and public click routes must not 500 on the new column.
 */
class OptionalSoftDeletingScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! self::columnReady($model)) {
            return;
        }

        $this->constrainWithoutTrashed($builder, $model);
    }

    public static function columnReady(Model $model): bool
    {
        try {
            $table = $model->getTable();
            $column = method_exists($model, 'getDeletedAtColumn')
                ? $model->getDeletedAtColumn()
                : 'deleted_at';

            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    public function extend(Builder $builder): void
    {
        parent::extend($builder);

        $builder->onDelete(function (Builder $builder) {
            if (! self::columnReady($builder->getModel())) {
                return 0;
            }

            $column = $this->getDeletedAtColumn($builder);

            return $builder->update([
                $column => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    protected function addOnlyTrashed(Builder $builder)
    {
        $builder->macro('onlyTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope($this);

            if (! self::columnReady($model)) {
                return $builder->whereRaw('0 = 1');
            }

            $column = $model->getQualifiedDeletedAtColumn();

            return $builder->whereNotNull($column)
                ->where($column, '>=', self::plausibleFloor($model))
                ->where($column, '<=', self::plausibleCeil($model));
        });
    }

    protected function addWithoutTrashed(Builder $builder)
    {
        $builder->macro('withoutTrashed', function (Builder $builder) {
            $model = $builder->getModel();
            $builder->withoutGlobalScope($this);

            if (! self::columnReady($model)) {
                return $builder;
            }

            return $this->constrainWithoutTrashed($builder, $model);
        });
    }

    /**
     * Leftover Hostinger strings are not a real delete. SQLite whereNull
     * misses them, so live notices vanished and Trash listed garbage.
     */
    private function constrainWithoutTrashed(Builder $builder, Model $model): Builder
    {
        $column = $model->getQualifiedDeletedAtColumn();
        $floor = self::plausibleFloor($model);
        $ceil = self::plausibleCeil($model);

        return $builder->where(function (Builder $q) use ($column, $floor, $ceil) {
            $q->whereNull($column)
                ->orWhere($column, '>', $ceil)
                ->orWhere($column, '<', $floor);
        });
    }

    private static function plausibleFloor(Model $model): string
    {
        return defined($model::class.'::PLAUSIBLE_SQL_DATETIME_FLOOR')
            ? $model::PLAUSIBLE_SQL_DATETIME_FLOOR
            : ToleratesUnparseableDates::PLAUSIBLE_SQL_DATETIME_FLOOR;
    }

    private static function plausibleCeil(Model $model): string
    {
        return defined($model::class.'::PLAUSIBLE_SQL_DATETIME_CEIL')
            ? $model::PLAUSIBLE_SQL_DATETIME_CEIL
            : ToleratesUnparseableDates::PLAUSIBLE_SQL_DATETIME_CEIL;
    }
}
