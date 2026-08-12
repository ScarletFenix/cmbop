<?php

namespace Tests\Unit;

use App\Models\Site;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePublicDiskUrlTest extends TestCase
{
    #[Test]
    public function public_disk_url_prefers_media_stream_path(): void
    {
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('sites/cover.webp'));
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('/storage/sites/cover.webp'));
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('storage/sites/cover.webp'));
        $this->assertNull(Site::publicDiskUrl(null));
        $this->assertNull(Site::publicDiskUrl(''));
    }

    #[Test]
    public function public_disk_url_fallbacks_include_media_then_storage(): void
    {
        $this->assertSame([
            '/media/sites/cover.webp',
            '/storage/sites/cover.webp',
        ], Site::publicDiskUrlFallbacks('sites/cover.webp'));
    }
}
