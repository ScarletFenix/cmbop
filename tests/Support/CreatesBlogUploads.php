<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

trait CreatesBlogUploads
{
    /**
     * Laravel's fake()->image() needs GD. Keep original bytes so blog upload
     * tests still run when this VM (or Hostinger) has no GD/WebP.
     */
    protected function fakeBlogUpload(string $name, int $width = 32, int $height = 32): UploadedFile
    {
        if (function_exists('imagecreatetruecolor')) {
            return UploadedFile::fake()->image($name, $width, $height);
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        return UploadedFile::fake()->createWithContent(
            $name,
            match ($ext) {
                'gif' => $this->tinyGifBytes(),
                'png' => $this->tinyPngBytes(),
                default => $this->tinyJpegBytes(),
            }
        );
    }

    /**
     * 16×16 JPEG that libjpeg / cwebp accept. A magic-bytes stub (or a
     * JPEG padded with junk before FFD9) is refused by cwebp as
     * "Invalid SOS parameters" / "Didn't expect more than one scan".
     */
    protected function tinyJpegBytes(): string
    {
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAgAAAQABAAD//gAQTGF2YzYwLjMxLjEwMgD/2wBDAAgEBAQEBAUFBQUFBQYGBgYGBgYGBgYGBgYHBwcICAgHBwcGBgcHCAgICAkJCQgICAgJCQoKCgwMCwsODg4RERT/xABMAAEBAAAAAAAAAAAAAAAAAAAABgEBAQAAAAAAAAAAAAAAAAAABgcQAQAAAAAAAAAAAAAAAAAAAAARAQAAAAAAAAAAAAAAAAAAAAD/wAARCAAQABADASIAAhEAAxEA/9oADAMBAAIRAxEAPwCLAFF/f//Z'
        ) ?: '';

        if ($jpeg === '' || ! str_starts_with($jpeg, "\xff\xd8\xff") || ! str_ends_with($jpeg, "\xff\xd9")) {
            return $this->validPngBytes(16, 16);
        }

        return $jpeg;
    }

    protected function tinyPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ) ?: "\x89PNG\r\n\x1a\n".str_repeat('P', 80).'IEND';
    }

    /**
     * Screenshot providers refuse bodies ≤ 500 bytes. Must stay a real PNG
     * so cwebp / GD can convert it (null-padded 1×1 PNGs are rejected).
     */
    protected function screenshotRasterBytes(): string
    {
        return $this->validPngBytes(48, 48, null);
    }

    /**
     * Noisy PNG larger than ArticlePreviewImage::SKIP_UNDER_BYTES so preview
     * compression actually runs (a solid-color PNG compresses under that floor).
     */
    protected function largePreviewPngBytes(): string
    {
        return $this->validPngBytes(180, 180, null);
    }

    /**
     * Valid PNG without GD. $rgb null = per-pixel noise (stays large after zlib).
     *
     * @param  array{0:int,1:int,2:int}|null  $rgb
     */
    protected function validPngBytes(int $width = 16, int $height = 16, ?array $rgb = [12, 80, 160]): string
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                if ($rgb === null) {
                    $raw .= pack('C3', ($x * 3 + $y) & 255, ($y * 5) & 255, ($x + $y) & 255);
                } else {
                    $raw .= pack('C3', $rgb[0] & 255, $rgb[1] & 255, $rgb[2] & 255);
                }
            }
        }

        $ihdr = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
        $deflate = function_exists('zlib_encode')
            ? zlib_encode($raw, ZLIB_ENCODING_DEFLATE, 6)
            : gzcompress($raw, 6);

        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('IDAT', $deflate)
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    protected function tinyGifBytes(): string
    {
        return 'GIF89a'.pack('v2', 1, 1)."\x00\x00\x00,\x00\x00\x00\x00".pack('v2', 1, 1)."\x00\x02\x02\x44\x01\x00;";
    }
}
