<?php

namespace Tests\Unit;

use App\Services\SiteEnrichment\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizationUploadTest extends TestCase
{
    public function test_uploaded_png_is_stored_as_webp(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');

        $img = imagecreatetruecolor(80, 60);
        $bg = imagecolorallocate($img, 30, 80, 120);
        imagefilledrectangle($img, 0, 0, 80, 60, $bg);
        $tmp = tempnam(sys_get_temp_dir(), 'cmbop-png-');
        $this->assertIsString($tmp);
        imagepng($img, $tmp);
        imagedestroy($img);

        $file = new UploadedFile($tmp, 'cover-shot.png', 'image/png', null, true);

        try {
            $path = app(ImageOptimizationService::class)->storeUploadedImageAsWebp($file, 'sites');
            $this->assertIsString($path);
            $this->assertStringStartsWith('sites/', $path);
            $this->assertStringEndsWith('.webp', $path);
            Storage::disk('public')->assertExists($path);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($path));
        } finally {
            @unlink($tmp);
        }
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

    public function test_safe_store_keeps_gif_and_refuses_unconverted_jpeg(): void
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

        if (function_exists('imagewebp')) {
            return;
        }

        $jpgTmp = tempnam(sys_get_temp_dir(), 'cmbop-safe-jpg-');
        $this->assertIsString($jpgTmp);
        file_put_contents($jpgTmp, "\xff\xd8\xff\xdbnot-reencoded");
        $jpg = new UploadedFile($jpgTmp, 'raw.jpg', 'image/jpeg', null, true);

        try {
            $this->assertNull($service->storeSafePublicImage($jpg, 'blogs/featured'));
        } finally {
            @unlink($jpgTmp);
        }
    }
}
