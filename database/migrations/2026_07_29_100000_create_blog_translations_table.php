<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: runtime heal (CuratedBlogSync) may have created this already.
        if (Schema::hasTable('blog_translations')) {
            return;
        }

        Schema::create('blog_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['blog_id', 'locale']);
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_translations');
    }
};
