<?php

use App\Services\CuratedBlogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Production still only showed the old Backlinks post after #163 —
 * force another curated sync (and schema heal) on migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        CuratedBlogSync::ensureSchema();
        CuratedBlogSync::sync();
    }

    public function down(): void
    {
        //
    }
};
