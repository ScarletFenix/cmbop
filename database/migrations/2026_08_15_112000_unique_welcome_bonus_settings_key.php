<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The create migration only adds the unique when the table did not exist.
     * Duplicate `config` rows let Disable update one row while grants read
     * another still set to on.
     */
    public function up(): void
    {
        if (! Schema::hasTable('welcome_bonus_settings') || ! Schema::hasColumn('welcome_bonus_settings', 'key')) {
            return;
        }

        if ($this->hasUniqueOn('key')) {
            return;
        }

        $this->collapseDuplicateKeys();

        if ($this->hasDuplicateKeys()) {
            return;
        }

        Schema::table('welcome_bonus_settings', function (Blueprint $table) {
            $table->unique('key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('welcome_bonus_settings') || ! $this->hasUniqueOn('key')) {
            return;
        }

        Schema::table('welcome_bonus_settings', function (Blueprint $table) {
            $table->dropUnique(['key']);
        });
    }

    private function collapseDuplicateKeys(): void
    {
        $keys = DB::table('welcome_bonus_settings')
            ->select('key')
            ->whereNotNull('key')
            ->where('key', '!=', '')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('key');

        foreach ($keys as $key) {
            $rows = DB::table('welcome_bonus_settings')
                ->where('key', $key)
                ->orderBy('id')
                ->get();

            $keepId = $rows->first()?->id;
            foreach ($rows as $row) {
                if ($this->rowLooksDisabled($row)) {
                    $keepId = $row->id;
                    break;
                }
            }

            if ($keepId === null) {
                continue;
            }

            DB::table('welcome_bonus_settings')
                ->where('key', $key)
                ->where('id', '!=', $keepId)
                ->delete();
        }
    }

    private function rowLooksDisabled(object $row): bool
    {
        $raw = $row->value ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || ! array_key_exists('enabled', $raw)) {
            return true;
        }

        $enabled = $raw['enabled'];
        if (is_bool($enabled)) {
            return $enabled === false;
        }

        if (is_int($enabled) || is_float($enabled) || is_string($enabled)) {
            return ! filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return true;
    }

    private function hasDuplicateKeys(): bool
    {
        return DB::table('welcome_bonus_settings')
            ->select('key')
            ->whereNotNull('key')
            ->where('key', '!=', '')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasUniqueOn(string $column): bool
    {
        try {
            foreach (Schema::getIndexes('welcome_bonus_settings') as $index) {
                if (! empty($index['unique']) && ($index['columns'] ?? []) === [$column]) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
