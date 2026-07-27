<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class LiveLinkChecklistBlogSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-live-link-checklist');

        if ($this->command) {
            $this->command->info(trim(Artisan::output()));
        }
    }
}
