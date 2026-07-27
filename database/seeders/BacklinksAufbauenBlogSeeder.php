<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class BacklinksAufbauenBlogSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-backlinks-aufbauen');

        if ($this->command) {
            $this->command->info(trim(Artisan::output()));
        }
    }
}
