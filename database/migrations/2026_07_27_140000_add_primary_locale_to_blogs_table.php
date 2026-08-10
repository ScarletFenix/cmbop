<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('blogs')) {
            return;
        }

        Schema::table('blogs', function (Blueprint $table) {
            if (! Schema::hasColumn('blogs', 'primary_locale')) {
                $table->string('primary_locale', 5)->nullable()->after('slug');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('blogs') || ! Schema::hasColumn('blogs', 'primary_locale')) {
            return;
        }

        Schema::table('blogs', function (Blueprint $table) {
            $table->dropColumn('primary_locale');
        });
    }
};
