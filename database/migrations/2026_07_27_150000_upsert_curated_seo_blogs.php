<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure curated SEO pillar posts exist in `blogs` so they appear in Admin → Blogs
 * after deploy (code alone does not insert rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        Artisan::call('blog:upsert-curated');
    }

    public function down(): void
    {
        // Keep content — curated posts are managed in admin after insert.
    }
};
