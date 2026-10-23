<?php

namespace Database\Seeders;

use App\Support\ThinBlogRedirects;
use Illuminate\Database\Seeder;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        if (class_exists(ThinBlogRedirects::class)) {
            ThinBlogRedirects::unpublishLegacy();
        }
    }
}
