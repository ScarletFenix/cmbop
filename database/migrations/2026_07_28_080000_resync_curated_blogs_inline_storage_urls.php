<?php

use App\Services\CuratedBlogSync;
use App\Support\BlogInlineImages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Re-publish curated pillar posts so inline images use /storage/blogs/content/
     * (public /assets/img/blog/ paths 404 on some production deploys).
     */
    public function up(): void
    {
        BlogInlineImages::publishAllFromPublicAssets();
        CuratedBlogSync::ensureSchema();
        CuratedBlogSync::sync();
        Cache::forget('curated_blogs_present_v1');
        Cache::forget('curated_blogs_inline_storage_v1');
    }

    public function down(): void
    {
        // Content can be re-synced forward; no destructive rollback.
    }
};
