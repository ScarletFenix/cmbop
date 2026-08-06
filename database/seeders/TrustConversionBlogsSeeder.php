<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class TrustConversionBlogsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-trust-conversion');
    }
}
