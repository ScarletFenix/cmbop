<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blog tags are echoed straight into markup by the index, the post page and the
 * sitemap. Blade cannot echo an array, so a single row whose JSON nests one used
 * to return a 500 for the entire blog section rather than for that one row.
 */
class BlogTagRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(string $slug, mixed $tags): Blog
    {
        $blog = Blog::create([
            'title' => 'Post '.$slug,
            'slug' => $slug,
            'content' => '<p>Body copy for the post.</p>',
            'excerpt' => 'Body copy',
            'status' => 'published',
            'published_at' => now(),
            'tags' => $tags,
        ]);

        BlogTranslation::create([
            'blog_id' => $blog->id,
            'locale' => 'en',
            'title' => $blog->title,
            'slug' => $blog->slug,
            'excerpt' => $blog->excerpt,
            'content' => $blog->content,
            'is_published' => true,
        ]);

        return $blog;
    }

    public function test_a_row_with_nested_tags_no_longer_takes_down_the_blog(): void
    {
        $this->makePost('nested-tags', [['seo', 'links'], 'guest posts']);

        $this->get('/blog')->assertOk();
        $this->get('/blog/nested-tags')->assertOk();
    }

    public function test_nested_tags_are_flattened_rather_than_dropped(): void
    {
        $blog = $this->makePost('flattened', [['seo', 'links'], 'guest posts']);

        $this->assertSame(['seo', 'links', 'guest posts'], $blog->fresh()->tags);
    }

    public function test_ordinary_tags_are_untouched(): void
    {
        $blog = $this->makePost('ordinary', ['seo', 'backlinks']);

        $this->assertSame(['seo', 'backlinks'], $blog->fresh()->tags);
        $this->get('/blog')->assertOk()->assertSee('backlinks');
    }

    public function test_the_admin_comma_string_still_saves_as_a_list(): void
    {
        $blog = $this->makePost('comma', 'seo, link building , outreach');

        $this->assertSame(['seo', 'link building', 'outreach'], $blog->fresh()->tags);
        $this->assertSame('seo, link building, outreach', $blog->fresh()->formatted_tags);
    }

    public function test_blank_and_duplicate_tags_are_dropped(): void
    {
        $blog = $this->makePost('messy', ['seo', '', '  ', 'seo', 'outreach']);

        $this->assertSame(['seo', 'outreach'], $blog->fresh()->tags);
    }

    public function test_a_row_with_no_tags_reads_as_an_empty_list(): void
    {
        $blog = $this->makePost('untagged', null);

        $this->assertSame([], $blog->fresh()->tags);
        $this->assertSame('', $blog->fresh()->formatted_tags);
        $this->get('/blog')->assertOk();
    }

    public function test_legacy_double_encoded_json_still_reads(): void
    {
        $blog = $this->makePost('legacy', ['seo']);
        // Some rows were written by code that json_encoded before assigning.
        \DB::table('blogs')->where('id', $blog->id)->update(['tags' => '"[\"seo\",\"links\"]"']);

        $this->assertIsArray($blog->fresh()->tags);
        $this->get('/blog')->assertOk();
    }
}
