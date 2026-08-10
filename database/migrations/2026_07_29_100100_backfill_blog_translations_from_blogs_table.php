<?php

use App\Services\CuratedBlogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blog_translations')) {
            return;
        }

        CuratedBlogSync::backfillTranslationsFromBlogs();
    }

    public function down(): void
    {
        // Intentionally left empty — runtime heal and republish may recreate rows.
    }
};
