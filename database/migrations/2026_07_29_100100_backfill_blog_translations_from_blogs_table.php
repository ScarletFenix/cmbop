<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $usedSlugs = DB::table('blog_translations')->pluck('slug')->all();
        $used = array_fill_keys($usedSlugs, true);

        DB::table('blogs')->orderBy('id')->chunkById(100, function ($blogs) use (&$used): void {
            foreach ($blogs as $blog) {
                $locale = in_array($blog->primary_locale, ['en', 'de', 'fr', 'nl'], true)
                    ? $blog->primary_locale
                    : 'en';

                $exists = DB::table('blog_translations')
                    ->where('blog_id', $blog->id)
                    ->where('locale', $locale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $baseSlug = $blog->slug ?: Str::slug((string) $blog->title);
                $slug = $baseSlug;
                $counter = 1;
                while (isset($used[$slug])) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }
                $used[$slug] = true;

                DB::table('blog_translations')->insert([
                    'blog_id' => $blog->id,
                    'locale' => $locale,
                    'title' => $blog->title ?: 'Untitled',
                    'slug' => $slug,
                    'excerpt' => $blog->excerpt,
                    'content' => $blog->content ?: '',
                    'meta_title' => null,
                    'meta_description' => null,
                    'is_published' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('blog_translations')->delete();
    }
};
