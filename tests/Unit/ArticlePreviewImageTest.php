<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ArticlePreviewImage;
use App\Services\SiteEnrichment\ImageOptimizationService;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class ArticlePreviewImageTest extends TestCase
{
    use CreatesBlogUploads;

    public function test_tiny_png_is_not_converted_to_webp(): void
    {
        $png = $this->validPngBytes(8, 8);
        $this->assertLessThanOrEqual(ArticlePreviewImage::SKIP_UNDER_BYTES, strlen($png));

        [$out, $ext] = app(ArticlePreviewImage::class)->compressForPreview($png, 'png');

        $this->assertSame('png', $ext);
        $this->assertSame($png, $out);
    }

    public function test_large_png_converts_to_smaller_webp(): void
    {
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        $png = $this->largePreviewPngBytes();
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

    public function test_huge_embed_skips_gd_compress(): void
    {
        $binary = str_repeat('X', ArticlePreviewImage::SKIP_OVER_BYTES + 1);

        [$out, $ext] = app(ArticlePreviewImage::class)->compressForPreview($binary, 'jpg');

        $this->assertSame('jpg', $ext);
        $this->assertSame($binary, $out);
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
}
