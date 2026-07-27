<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DofollowNofollowAnkertexteBlogSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-dofollow-nofollow-ankertexte');

        if ($this->command) {
            $this->command->info(trim(Artisan::output()));
        }
    }
}
