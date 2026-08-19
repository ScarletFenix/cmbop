<?php

namespace Tests\Unit;

use App\Services\SiteEnrichment\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class ImageOptimizationUploadTest extends TestCase
{
    use CreatesBlogUploads;

    public function test_uploaded_png_is_stored_as_webp(): void
    {
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        Storage::fake('public');
        $file = $this->fakeBlogUpload('cover-shot.png', 80, 60);

        $path = app(ImageOptimizationService::class)->storeUploadedImageAsWebp($file, 'sites');
        $this->assertIsString($path);
        $this->assertStringStartsWith('sites/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($path));
    }

    public function test_gif_upload_is_not_converted(): void
    {
        Storage::fake('public');

        $tmp = tempnam(sys_get_temp_dir(), 'cmbop-gif-');
        $this->assertIsString($tmp);
        file_put_contents($tmp, 'GIF89a'.str_repeat("\x00", 20));

        $file = new UploadedFile($tmp, 'animated.gif', 'image/gif', null, true);

        try {
            $this->assertNull(app(ImageOptimizationService::class)->storeUploadedImageAsWebp($file, 'sites'));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_safe_store_keeps_gif_stores_valid_jpeg_and_refuses_garbage(): void
    {
        Storage::fake('public');
        $service = app(ImageOptimizationService::class);

        $gifTmp = tempnam(sys_get_temp_dir(), 'cmbop-safe-gif-');
        $this->assertIsString($gifTmp);
        file_put_contents($gifTmp, 'GIF89a'.str_repeat("\x00", 20));
        $gif = new UploadedFile($gifTmp, 'animated.gif', 'image/gif', null, true);

        try {
            $gifPath = $service->storeSafePublicImage($gif, 'blogs/content');
            $this->assertIsString($gifPath);
            $this->assertStringContainsString('blogs/content/', $gifPath);
            Storage::disk('public')->assertExists($gifPath);
        } finally {
            @unlink($gifTmp);
        }

        $junkTmp = tempnam(sys_get_temp_dir(), 'cmbop-safe-junk-');
        $this->assertIsString($junkTmp);
        file_put_contents($junkTmp, "\xff\xd8\xff\xdbnot-reencoded");
        $junk = new UploadedFile($junkTmp, 'raw.jpg', 'image/jpeg', null, true);

        try {
            $this->assertNull($service->storeSafePublicImage($junk, 'blogs/featured'));
        } finally {
            @unlink($junkTmp);
        }

        $jpgTmp = tempnam(sys_get_temp_dir(), 'cmbop-safe-jpg-');
        $this->assertIsString($jpgTmp);
        file_put_contents($jpgTmp, $this->tinyJpegBytes());
        $jpg = new UploadedFile($jpgTmp, 'hero.jpg', 'image/jpeg', null, true);

        try {
            $jpgPath = $service->storeSafePublicImage($jpg, 'blogs/featured');
            $this->assertIsString($jpgPath);
            $this->assertStringContainsString('blogs/featured/', $jpgPath);
            Storage::disk('public')->assertExists($jpgPath);
            if (ImageOptimizationService::canEncodeWebp()) {
                $this->assertStringEndsWith('.webp', $jpgPath);
                $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($jpgPath));
            } else {
                $this->assertMatchesRegularExpression('/\.jpe?g$/i', $jpgPath);
            }
        } finally {
            @unlink($jpgTmp);
        }
    }

    public function test_safe_store_keeps_valid_jpeg_when_webp_unavailable(): void
    {
        Storage::fake('public');
        $file = $this->fakeBlogUpload('cover.jpg', 80, 60);

        $path = app(ImageOptimizationService::class)->storeSafePublicImage($file, 'sites');
        $this->assertIsString($path);
        $this->assertStringStartsWith('sites/', $path);
        Storage::disk('public')->assertExists($path);

        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', $path);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($path));
        } else {
            $this->assertMatchesRegularExpression('/\.jpe?g$/i', $path);
            $this->assertStringStartsWith("\xff\xd8\xff", Storage::disk('public')->get($path));
        }
    }

    public function test_to_webp_encodes_png_with_available_backend(): void
    {
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        $webp = app(ImageOptimizationService::class)->toWebp($this->tinyPngBytes());
        $this->assertIsString($webp);
        $this->assertStringStartsWith('RIFF', $webp);
    }

    public function test_to_webp_encodes_jpeg_with_available_backend(): void
    {
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        $webp = app(ImageOptimizationService::class)->toWebp($this->tinyJpegBytes());
        $this->assertIsString($webp);
        $this->assertStringStartsWith('RIFF', $webp);
    }

    public function test_store_optimized_keeps_original_png_when_webp_unavailable(): void
    {
        Storage::fake('public');
        $png = $this->tinyPngBytes();

        $stored = app(ImageOptimizationService::class)->storeOptimizedWebp($png, 'site-screenshots', 'site-1-original');
        $this->assertNotNull($stored);
        $this->assertTrue(Storage::disk('public')->exists($stored['path']));

        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', $stored['path']);
        } else {
            $this->assertStringEndsWith('.png', $stored['path']);
            $this->assertNull($stored['thumb_path']);
        }
    }
}
