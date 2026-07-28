<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class PublisherPlatformGuideBlogSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-publisher-platform-guide');
    }
}
