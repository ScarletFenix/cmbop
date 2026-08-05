<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertisers can hide a domain they have already opened — and have that hide
 * stick across reloads — without erasing the disclosure audit trail.
 *
 * A null concealed_at means "show it". A timestamp means they clicked the eye
 * closed; the row stays so pace history and publisher-report audits keep the
 * fact that they once knew the address.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_url_reveals')) {
            return;
        }

        if (Schema::hasColumn('site_url_reveals', 'concealed_at')) {
            return;
        }

        Schema::table('site_url_reveals', function (Blueprint $table) {
            $table->timestamp('concealed_at')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_url_reveals')) {
            return;
        }

        if (! Schema::hasColumn('site_url_reveals', 'concealed_at')) {
            return;
        }

        Schema::table('site_url_reveals', function (Blueprint $table) {
            $table->dropColumn('concealed_at');
        });
    }
};
