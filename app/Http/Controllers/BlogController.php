<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogSync;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    /**
     * Display a listing of published blog posts.
     */
    public function index()
    {
        CuratedBlogSync::ensurePresent();
        $requestedLocale = public_locale();

        $blog = Blog::published()
            ->with(['translations' => function ($query) {
                $query->where('is_published', true);
            }])
            ->orderByDesc('published_at')
            ->paginate(12);

        $blog->getCollection()->transform(function (Blog $post) use ($requestedLocale) {
            $translation = $post->displayTranslation($requestedLocale, 'en');
            if ($translation) {
                $post->setAttribute('title', $translation->title);
                $post->setAttribute('slug', $translation->slug);
                $post->setAttribute('excerpt', $translation->excerpt ?: $post->excerpt);
                $post->setAttribute('content', $translation->content ?: $post->content);
                $post->setAttribute('resolved_locale', $translation->locale);
                $post->setAttribute('fallback_notice', $translation->locale !== $requestedLocale);
            } else {
                $post->setAttribute('resolved_locale', $post->primary_locale ?: 'en');
                $post->setAttribute('fallback_notice', false);
            }

            return $post;
        });

        return view('pages.blog', compact('blog'));
    }

    /**
     * Display a single blog post.
     *
     * Locale-prefixed routes include a {locale} parameter. Reading the slug from
     * the route bag avoids Laravel's array_values() controller dispatch binding
     * the locale into a lone $slug argument.
     */
    public function show(Request $request)
    {
        CuratedBlogSync::ensurePresent();
        $requestedLocale = public_locale();
        $fallbackLocale = 'en';

        $slug = (string) $request->route('slug');

        $translation = BlogTranslation::query()
            ->where('locale', $requestedLocale)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (! $translation && $requestedLocale !== $fallbackLocale) {
            $translation = BlogTranslation::query()
                ->where('locale', $fallbackLocale)
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        }

        // Translation slugs are globally unique. /blog/{de-slug} must still
        // resolve when blogs.slug was renamed or differs from the DE row.
        if (! $translation) {
            $translation = BlogTranslation::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        }

        $blog = null;
        if ($translation) {
            $blog = Blog::published()
                ->with(['translations' => function ($query) {
                    $query->where('is_published', true);
                }])
                ->where('id', $translation->blog_id)
                ->first();
        }

        if (! $blog) {
            $blog = Blog::published()
                ->with(['translations' => function ($query) {
                    $query->where('is_published', true);
                }])
                ->where('slug', $slug)
                ->firstOrFail();
        }

        $display = $blog->displayTranslation($requestedLocale, $fallbackLocale);
        if ($display) {
            $translation = $display;
        }

        if (! $translation) {
            $translation = new BlogTranslation([
                'blog_id' => $blog->id,
                'locale' => $blog->primary_locale ?: $fallbackLocale,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'content' => $blog->content,
                'is_published' => true,
            ]);
        }

        $fallbackUsed = $translation->locale !== $requestedLocale;
        $canonicalUrl = $blog->canonicalUrl($translation->locale, $fallbackLocale);
        $availableLocales = $blog->availableLocales();
        if ($availableLocales === []) {
            $availableLocales = [$translation->locale ?: $fallbackLocale];
        }
        $hreflangPath = 'blog/'.$translation->slug;

        $related = Blog::published()
            ->with(['translations' => function ($query) {
                $query->where('is_published', true);
            }])
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        $related->transform(function (Blog $post) use ($requestedLocale) {
            $resolved = $post->displayTranslation($requestedLocale, 'en');
            if ($resolved) {
                $post->setAttribute('title', $resolved->title);
                $post->setAttribute('slug', $resolved->slug);
                $post->setAttribute('content', $resolved->content ?: $post->content);
            }

            return $post;
        });

        return view('pages.blog-single', compact(
            'blog',
            'related',
            'translation',
            'requestedLocale',
            'fallbackUsed',
            'canonicalUrl',
            'availableLocales',
            'hreflangPath'
        ));
    }
}
