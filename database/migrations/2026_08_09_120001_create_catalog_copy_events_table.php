<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinct domain/URL clipboard copies from the advertiser catalog.
 *
 * Counted toward copy-strike thresholds; site_id is preferred for dedupe,
 * with normalized_host as a fallback when the row id is unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_copy_events')) {
            return;
        }

        Schema::create('catalog_copy_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('normalized_host', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'site_id', 'created_at']);
            $table->index(['user_id', 'normalized_host', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_copy_events');
    }
};
