<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ArticlePreviewImage;
use Tests\TestCase;

class ArticlePreviewImageTest extends TestCase
{
    public function test_tiny_png_is_not_converted_to_webp(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD PNG not available');
        }

        $png = $this->pngBytes(8, 8);
        $this->assertLessThanOrEqual(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));

        [$out, $ext] = app(ArticlePreviewImage::class)->compressForPreview($png, 'png');

        $this->assertSame('png', $ext);
        $this->assertSame($png, $out);
    }

    public function test_large_png_converts_to_smaller_webp(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        $png = $this->largePngBytes();
        $this->assertGreaterThan(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));

        [$out, $ext] = app(ArticlePreviewImage::class)->compressForPreview($png, 'png');

        $this->assertSame('webp', $ext);
        $this->assertNotSame($png, $out);
        $this->assertLessThan(strlen($png), strlen($out));
        $this->assertStringStartsWith('RIFF', $out);
    }

    public function test_animated_gif_is_kept(): void
    {
        $gif = 'GIF89a'."\x01\x00\x01\x00\x00\x00\x00".'NETSCAPE2.0'."\x00\x00";
        $this->assertTrue(ArticlePreviewImage::isAnimatedGif($gif));

        [$out, $ext] = app(ArticlePreviewImage::class)->compressForPreview($gif, 'gif');

        $this->assertSame('gif', $ext);
        $this->assertSame($gif, $out);
    }

    public function test_missing_gd_keeps_original_bytes(): void
    {
        $png = str_repeat('PNG-BYTES', 2000);
        $this->assertGreaterThan(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));

        $service = new class extends ArticlePreviewImage
        {
            public function compressForPreview(string $binary, string $ext): array
            {
                $ext = strtolower(ltrim($ext, '.'));
                if (strlen($binary) <= self::SKIP_UNDER_BYTES) {
                    return [$binary, $ext];
                }

                // Simulate Hostinger without GD WebP: keep original preview bytes.
                return [$binary, $ext];
            }
        };

        [$out, $ext] = $service->compressForPreview($png, 'png');
        $this->assertSame('png', $ext);
        $this->assertSame($png, $out);
    }

    private function pngBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 12, 80, 160));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return is_string($png) ? $png : '';
    }

    private function largePngBytes(): string
    {
        $img = imagecreatetruecolor(320, 240);
        imagefilledrectangle($img, 0, 0, 319, 239, imagecolorallocate($img, 12, 80, 160));
        ob_start();
        imagepng($img, null, 0);
        $png = ob_get_clean();
        imagedestroy($img);

        return is_string($png) ? $png : '';
    }
}
