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

    protected function tinyJpegBytes(): string
    {
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHR'
            .'ofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgy'
            .'IRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/'
            .'wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAA'
            .'AAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgB'
            .'AQABPwCf/9k='
        ) ?: '';

        if ($jpeg === '' || ! str_starts_with($jpeg, "\xff\xd8\xff") || ! str_ends_with($jpeg, "\xff\xd9")) {
            return "\xff\xd8\xff\xe0".str_repeat('J', 120)."\xff\xd9";
        }

        if (strlen($jpeg) < 100) {
            $jpeg = substr($jpeg, 0, -2).str_repeat("\x00", 100)."\xff\xd9";
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
     * Screenshot providers refuse bodies ≤ 500 bytes.
     */
    protected function screenshotRasterBytes(): string
    {
        return $this->tinyPngBytes().str_repeat("\x00", 520);
    }

    protected function tinyGifBytes(): string
    {
        return 'GIF89a'.pack('v2', 1, 1)."\x00\x00\x00,\x00\x00\x00\x00".pack('v2', 1, 1)."\x00\x02\x02\x44\x01\x00;";
    }
}
