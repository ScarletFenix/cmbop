<?php
// app/Http/Controllers/BlogController.php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogController extends Controller
{
    /**
     * Display a listing of published blog posts.
     */
    public function index()
    {
        $blog = Blog::where('status', 'published')
                    ->orderBy('published_at', 'desc')
                    ->paginate(12);
        
        return view('pages.blog', compact('blog'));
    }

    /**
     * Display a single blog post.
     */
    public function show($slug)
    {
        $blog = Blog::where('slug', $slug)
                    ->where('status', 'published')
                    ->firstOrFail();
        
        // Increment view count (add views column to your blogs table first)
        // $blog->increment('views');
        
        return view('pages.blog-single', compact('blog'));
    }
}