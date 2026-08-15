<?php

namespace App\Services;

/**
 * Backward-compatible name for the pillar-post registry.
 *
 * CuratedBlogSync lives in this namespace and calls CuratedBlogCatalog::slugs().
 * Without this class (or an explicit Support import), Admin → Blogs 500s with
 * "Class App\Services\CuratedBlogCatalog not found".
 */
class CuratedBlogCatalog extends \App\Support\CuratedBlogCatalog {}
