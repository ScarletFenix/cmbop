<?php

use App\Services\CuratedBlogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Ship the EN trust/conversion pillars on migrate (idempotent upsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            CuratedBlogSync::ensureSchema();
            Artisan::call('blog:upsert-trust-conversion');
        } catch (Throwable $e) {
            Log::warning('Trust conversion blog upsert skipped during migrate', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Content upserts are not reversed; admins can unpublish in the UI.
    }
};
