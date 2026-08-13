<?php

namespace App\Console\Commands;

use App\Support\PublicStorageLink;
use Illuminate\Console\Command;

class EnsurePublicStorageLinkCommand extends Command
{
    protected $signature = 'media:ensure-link';

    protected $description = 'Ensure public/storage symlinks to the public disk root (MEDIA_PATH or storage/app/public)';

    public function handle(): int
    {
        $result = PublicStorageLink::ensure();

        if ($result['ok']) {
            $this->info($result['repaired']
                ? 'public/storage symlink repaired → '.config('filesystems.disks.public.root')
                : 'public/storage already points at '.config('filesystems.disks.public.root'));

            return self::SUCCESS;
        }

        $this->error($result['message'] ?? 'Could not ensure public/storage link.');

        return self::FAILURE;
    }
}
