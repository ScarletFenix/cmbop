<?php

use App\Services\CuratedBlogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Ship EN/DE/FR/NL language + market gap pillars (idempotent upsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            CuratedBlogSync::ensureSchema();
            Artisan::call('blog:upsert-language-market');
        } catch (Throwable $e) {
            Log::warning('Language/market blog upsert skipped during migrate', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Content upserts are not reversed.
    }
};
