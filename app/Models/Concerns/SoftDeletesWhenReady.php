<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OptionalSoftDeletingScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

trait SoftDeletesWhenReady
{
    use SoftDeletes {
        SoftDeletes::bootSoftDeletes as private bootAlwaysSoftDeletes;
        SoftDeletes::performDeleteOnModel as private performSoftDeleteOnModel;
        SoftDeletes::restore as private performRestore;
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new OptionalSoftDeletingScope);
    }

    protected function performDeleteOnModel()
    {
        if (! OptionalSoftDeletingScope::columnReady($this)) {
            // Hard-delete used to keep Hostinger from 500ing, but staff
            // "Delete" then permanently dropped the row and the undo bar lied.
            throw new \RuntimeException('Soft-delete column is not ready.');
        }

        return $this->performSoftDeleteOnModel();
    }

    public function restore()
    {
        if (! OptionalSoftDeletingScope::columnReady($this)) {
            return false;
        }

        $column = $this->getDeletedAtColumn();
        $raw = $this->getAttributes()[$column] ?? null;
        if ($raw !== null && $raw !== '' && ! ($this->{$column} instanceof \DateTimeInterface)) {
            // Leftover unparseable deleted_at is already "not trashed" in PHP.
            // Eloquent dirty-diff sees null→null and would skip the UPDATE.
            static::withTrashed()->whereKey($this->getKey())->update([$column => null]);
            $this->setAttribute($column, null);

            return true;
        }

        return $this->performRestore();
    }

    /**
     * Implicit route binding must not 500 when Hostinger is still missing the table.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        try {
            return parent::resolveRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        try {
            return parent::resolveSoftDeletableRouteBinding($value, $field);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function deletedAtColumnReady(): bool
    {
        try {
            $table = (new static)->getTable();

            return Schema::hasTable($table) && Schema::hasColumn($table, (new static)->getDeletedAtColumn());
        } catch (\Throwable) {
            return false;
        }
    }
}
