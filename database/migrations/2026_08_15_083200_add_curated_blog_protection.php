<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blogs')) {
            Schema::table('blogs', function (Blueprint $table) {
                if (! Schema::hasColumn('blogs', 'curated_key')) {
                    $table->string('curated_key')->nullable()->index()->after('slug');
                }
                if (! Schema::hasColumn('blogs', 'manually_edited_at')) {
                    $table->timestamp('manually_edited_at')->nullable()->after('updated_by');
                }
            });
        }

        if (! Schema::hasTable('curated_blog_tombstones')) {
            Schema::create('curated_blog_tombstones', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('curated_blog_tombstones');

        if (Schema::hasTable('blogs')) {
            Schema::table('blogs', function (Blueprint $table) {
                if (Schema::hasColumn('blogs', 'manually_edited_at')) {
                    $table->dropColumn('manually_edited_at');
                }
                if (Schema::hasColumn('blogs', 'curated_key')) {
                    $table->dropColumn('curated_key');
                }
            });
        }
    }
};
