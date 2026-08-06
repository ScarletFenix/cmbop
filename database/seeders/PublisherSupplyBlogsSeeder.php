<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class PublisherSupplyBlogsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-publisher-supply');
    }
}
