<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has been shown which publisher domain.
 *
 * Two jobs. It makes a reveal sticky — an advertiser who has already seen a
 * domain should never spend allowance on it again, or be re-masked on the next
 * page load. And it is the audit trail: when a publisher reports being
 * approached directly, this is the only record of who could have known.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_url_reveals')) {
            return;
        }

        Schema::create('site_url_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            // catalog | cart | order — how they came to see it.
            $table->string('source', 20)->default('catalog');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // One row per advertiser per site: revealing twice is not a second
            // disclosure and must not cost a second allowance.
            $table->unique(['user_id', 'site_id']);
            // The allowance and anomaly checks both count a user's recent rows.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_url_reveals');
    }
};
