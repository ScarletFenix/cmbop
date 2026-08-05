<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertisers must declare where the images in an article came from, so a
 * copyright complaint can be traced to what the uploader asserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('content_submissions', 'image_rights')) {
                $table->string('image_rights', 20)->nullable()->after('feature_image_url');
            }
            if (! Schema::hasColumn('content_submissions', 'image_rights_source')) {
                $table->text('image_rights_source')->nullable()->after('image_rights');
            }
            if (! Schema::hasColumn('content_submissions', 'image_rights_declared_at')) {
                $table->timestamp('image_rights_declared_at')->nullable()->after('image_rights_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            foreach (['image_rights_declared_at', 'image_rights_source', 'image_rights'] as $column) {
                if (Schema::hasColumn('content_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
