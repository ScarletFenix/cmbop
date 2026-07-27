<?php

namespace App\Http\Controllers;

use App\Models\Blog;
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

        $blog = Blog::published()
            ->orderByDesc('published_at')
            ->paginate(12);

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

        $slug = (string) $request->route('slug');

        $blog = Blog::published()
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Blog::published()
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('pages.blog-single', compact('blog', 'related'));
    }
}
