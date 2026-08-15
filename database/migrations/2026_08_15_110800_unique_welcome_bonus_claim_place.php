<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The create migration only adds uniques when the table did not exist.
     * A drifted Hostinger table without a unique IP index can grant €20 twice
     * to the same place under concurrent signups.
     */
    public function up(): void
    {
        if (! Schema::hasTable('welcome_bonus_claims')) {
            return;
        }

        if (Schema::hasColumn('welcome_bonus_claims', 'ip_address') && ! $this->hasUniqueOn('ip_address')) {
            $this->keepOldestRowPer('ip_address');
            if (! $this->hasDuplicatesOn('ip_address')) {
                Schema::table('welcome_bonus_claims', function (Blueprint $table) {
                    $table->unique('ip_address');
                });
            }
        }

        if (Schema::hasColumn('welcome_bonus_claims', 'user_id') && ! $this->hasUniqueOn('user_id')) {
            if (! $this->hasDuplicatesOn('user_id')) {
                Schema::table('welcome_bonus_claims', function (Blueprint $table) {
                    $table->unique('user_id');
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('welcome_bonus_claims')) {
            return;
        }

        Schema::table('welcome_bonus_claims', function (Blueprint $table) {
            if ($this->hasUniqueOn('ip_address')) {
                $table->dropUnique(['ip_address']);
            }
            if ($this->hasUniqueOn('user_id')) {
                $table->dropUnique(['user_id']);
            }
        });
    }

    private function keepOldestRowPer(string $column): void
    {
        $dupes = DB::table('welcome_bonus_claims')
            ->select($column, DB::raw('MIN(id) as keep_id'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $dupe) {
            DB::table('welcome_bonus_claims')
                ->where($column, $dupe->{$column})
                ->where('id', '!=', $dupe->keep_id)
                ->delete();
        }
    }

    private function hasDuplicatesOn(string $column): bool
    {
        return DB::table('welcome_bonus_claims')
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasUniqueOn(string $column): bool
    {
        try {
            foreach (Schema::getIndexes('welcome_bonus_claims') as $index) {
                if (empty($index['unique'])) {
                    continue;
                }
                if (($index['columns'] ?? []) === [$column]) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
