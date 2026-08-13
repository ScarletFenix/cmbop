<?php

namespace Tests\Unit;

use App\Support\PublicStorageLink;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicStorageLinkTest extends TestCase
{
    public function test_paths_equal_normalizes_slashes_and_trailing_sep(): void
    {
        $this->assertTrue(PublicStorageLink::pathsEqual('/var/media', '/var/media/'));
        $this->assertTrue(PublicStorageLink::pathsEqual('/var/media/sites', '/var/media/./sites'));
        $this->assertFalse(PublicStorageLink::pathsEqual('/var/media', '/var/other'));
    }

    public function test_path_is_publicly_reachable_in_unit_tests_when_disk_has_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('sites/probe.webp', 'webp-bytes');

        $this->assertTrue(PublicStorageLink::pathIsPubliclyReachable('sites/probe.webp'));
        $this->assertFalse(PublicStorageLink::pathIsPubliclyReachable('sites/missing.webp'));
    }

    public function test_ensure_reports_ok_when_link_already_correct(): void
    {
        $root = storage_path('app/public');
        Config::set('filesystems.disks.public.root', $root);

        $result = PublicStorageLink::ensure();
        $this->assertTrue($result['ok']);
    }
}
