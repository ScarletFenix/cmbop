<?php

namespace Tests\Unit;

use App\Support\PublicImageDimensions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicImageDimensionsTest extends TestCase
{
    public function test_http_urls_are_never_probed_and_return_null(): void
    {
        $this->assertNull(PublicImageDimensions::forUrl('https://cdn.example.com/a.png'));
        $this->assertNull(PublicImageDimensions::forUrl('http://127.0.0.1/media/blogs/content/a.jpg'));
        $this->assertNull(PublicImageDimensions::forUrl('data:image/png;base64,aaaa'));
        $this->assertNull(PublicImageDimensions::forUrl('/media/blogs/content/../featured/x.jpg'));
        $this->assertNull(PublicImageDimensions::forUrl('/media/sites/cover.webp'));
        $this->assertNull(PublicImageDimensions::forPublicDiskPath('blogs/content/../../.env'));
        $this->assertNull(PublicImageDimensions::forPublicDiskPath('sites/cover.webp'));
    }

    public function test_local_disk_file_returns_getimagesize_not_guessed_html_ratio(): void
    {
        $source = public_path('assets/img/blog/link-building-guide-roadmap.jpg');
        $this->assertFileExists($source);
        $info = getimagesize($source);
        $this->assertIsArray($info);
        $this->assertGreaterThan(1, (int) $info[0]);
        $this->assertGreaterThan(1, (int) $info[1]);

        Storage::disk('public')->put(
            'blogs/content/link-building-guide-roadmap.jpg',
            (string) file_get_contents($source)
        );

        $dims = PublicImageDimensions::forUrl('/media/blogs/content/link-building-guide-roadmap.jpg');
        $this->assertSame(['width' => (int) $info[0], 'height' => (int) $info[1]], $dims);

        $viaStorage = PublicImageDimensions::forUrl('/storage/blogs/content/link-building-guide-roadmap.jpg');
        $this->assertSame($dims, $viaStorage);

        $this->assertNotSame(
            ['width' => 1200, 'height' => 675],
            $dims,
            'Roadmap file must not be treated as the curated 1200×675 guess'
        );
    }

    public function test_bundled_asset_fallback_when_disk_copy_is_missing(): void
    {
        $source = public_path('assets/img/blog/link-building-guide-featured.jpg');
        $this->assertFileExists($source);
        $info = getimagesize($source);
        $this->assertIsArray($info);

        Storage::fake('public');
        $this->assertFalse(Storage::disk('public')->exists('blogs/content/link-building-guide-featured.jpg'));

        $dims = PublicImageDimensions::forUrl('/media/blogs/content/link-building-guide-featured.jpg');
        $this->assertSame(['width' => (int) $info[0], 'height' => (int) $info[1]], $dims);
    }
}
