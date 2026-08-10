<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('countries')) {
            return;
        }

        $existing = DB::table('countries')->where('code', 'li')->first();
        if ($existing) {
            DB::table('countries')->where('id', $existing->id)->update([
                'name' => 'Liechtenstein',
                'region' => 'Europe',
                'updated_at' => now(),
            ]);
            $countryId = (int) $existing->id;
        } else {
            $countryId = (int) DB::table('countries')->insertGetId([
                'code' => 'li',
                'name' => 'Liechtenstein',
                'region' => 'Europe',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('languages') || ! Schema::hasTable('country_language')) {
            return;
        }

        $german = DB::table('languages')->where('code', 'de')->first();
        if (! $german || $countryId < 1) {
            return;
        }

        $exists = DB::table('country_language')
            ->where('country_id', $countryId)
            ->where('language_id', $german->id)
            ->exists();

        if (! $exists) {
            DB::table('country_language')->insert([
                'country_id' => $countryId,
                'language_id' => $german->id,
                'is_primary' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep Liechtenstein; marketplace config controls visibility.
    }
};
