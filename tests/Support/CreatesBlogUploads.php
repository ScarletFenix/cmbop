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
            $ext === 'gif' ? $this->tinyGifBytes() : $this->tinyJpegBytes()
        );
    }

    protected function tinyJpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHR'
            .'ofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgy'
            .'IRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/'
            .'wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAA'
            .'AAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgB'
            .'AQABPwCf/9k='
        ) ?: 'not-a-jpeg';
    }

    protected function tinyGifBytes(): string
    {
        return 'GIF89a'.pack('v2', 1, 1)."\x00\x00\x00,\x00\x00\x00\x00".pack('v2', 1, 1)."\x00\x02\x02\x44\x01\x00;";
    }
}
