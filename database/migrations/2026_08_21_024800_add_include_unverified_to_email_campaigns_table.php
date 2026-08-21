<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_campaigns')
            || Schema::hasColumn('email_campaigns', 'include_unverified')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->boolean('include_unverified')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('email_campaigns')
            || ! Schema::hasColumn('email_campaigns', 'include_unverified')) {
            return;
        }

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('include_unverified');
        });
    }
};
