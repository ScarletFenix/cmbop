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
}
