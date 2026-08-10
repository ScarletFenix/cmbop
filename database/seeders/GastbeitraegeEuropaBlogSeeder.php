<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class GastbeitraegeEuropaBlogSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('blog:upsert-gastbeitraege-europa');

        if ($this->command) {
            $this->command->info(trim(Artisan::output()));
        }
    }
}
