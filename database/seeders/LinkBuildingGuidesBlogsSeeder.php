<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class LinkBuildingGuidesBlogsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-link-building-guides');
    }
}
