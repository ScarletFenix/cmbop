<?php

namespace App\Services\SiteEnrichment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageOptimizationService
{
    /**
     * Store binary image as optimized WebP (+ optional thumbnail).
     *
     * @return array{path: string, thumb_path: ?string}|null
     */
    public function storeOptimizedWebp(string $binary, string $directory, string $basename): ?array
    {
        $disk = Storage::disk('public');
        $directory = trim($directory, '/');
        $basename = Str::slug($basename) ?: 'site';
        $path = $directory.'/'.$basename.'.webp';
        $thumbPath = $directory.'/'.$basename.'-thumb.webp';

        $optimized = $this->toWebp($binary, (int) config('site_enrichment.screenshots.quality', 82));
        if ($optimized === null) {
            return $this->storeOriginalRaster($binary, $directory, $basename);
        }

        $disk->put($path, $optimized);

        $thumbBinary = $this->resizeToWebp(
            $binary,
            (int) config('site_enrichment.screenshots.thumb_width', 640),
            (int) config('site_enrichment.screenshots.quality', 80)
        );

        $thumbStored = null;
        if ($thumbBinary !== null) {
            $disk->put($thumbPath, $thumbBinary);
            $thumbStored = $thumbPath;
        }

        return [
            'path' => $path,
            'thumb_path' => $thumbStored,
        ];
    }

    public static function canEncodeWebp(): bool
    {
        return self::gdWebpAvailable() || self::imagickWebpAvailable() || self::cwebpBinary() !== null;
    }

    public function toWebp(string $binary, int $quality = 82): ?string
    {
        if ($binary === '') {
            return null;
        }

        $quality = max(1, min(100, $quality));

        return $this->toWebpViaGd($binary, $quality)
            ?? $this->toWebpViaImagick($binary, $quality)
            ?? $this->toWebpViaCwebp($binary, $quality);
    }

    public function resizeToWebp(string $binary, int $targetWidth, int $quality = 80): ?string
    {
        $quality = max(1, min(100, $quality));
        $viaCli = $this->toWebpViaCwebp($binary, $quality, $targetWidth);
        if ($viaCli !== null) {
            return $viaCli;
        }

        if (! self::gdWebpAvailable() || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW < 1 || $srcH < 1) {
            imagedestroy($source);

            return null;
        }

        $targetWidth = max(120, $targetWidth);
        if ($srcW <= $targetWidth) {
            imagedestroy($source);

            return $this->toWebp($binary, $quality);
        }

        $targetHeight = (int) max(1, round($srcH * ($targetWidth / $srcW)));
        $dest = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

        ob_start();
        $ok = imagewebp($dest, null, max(1, min(100, $quality)));
        $data = ob_get_clean();
        imagedestroy($source);
        imagedestroy($dest);

        return $ok && is_string($data) && $data !== '' ? $data : null;
    }

    /**
     * Generate a professional placeholder when capture fails. Drawn with GD
     * when present, otherwise a solid PNG. Encoded to WebP by toWebp().
     */
    public function storePlaceholder(string $directory, string $basename, string $label = 'Preview unavailable'): ?array
    {
        $binary = $this->placeholderRaster($label);
        if ($binary === null || $binary === '') {
            return null;
        }

        return $this->storeOptimizedWebp($binary, $directory, $basename.'-placeholder');
    }

    /**
     * Store a public-disk image. WebP when an encoder is available (GD,
     * Imagick, or cwebp). GIF stays GIF (animation). JPEG/PNG/WebP keep
     * original bytes only when they decode as a real image and conversion
     * is unavailable.
     */
    public function storeSafePublicImage(UploadedFile $file, string $directory): ?string
    {
        $converted = $this->storeUploadedImageAsWebp($file, $directory);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        if (! in_array($ext, ['gif', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }

        if ($ext !== 'gif') {
            $sourcePath = $file->getRealPath() ?: $file->getPathname();
            if (! is_string($sourcePath) || $sourcePath === '' || ! is_file($sourcePath)) {
                return null;
            }

            $binary = (string) file_get_contents($sourcePath);
            if ($ext === 'webp') {
                if (! $this->looksLikeWebp($binary)) {
                    return null;
                }
            } elseif (! $this->isDecodableRasterImage($binary, $ext)) {
                return null;
            }
        }

        try {
            $stored = $file->store(trim($directory, '/'), 'public');
        } catch (\Throwable) {
            return null;
        }

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Persist a captured screenshot as JPEG/PNG/WebP when no encoder can re-encode.
     *
     * @return array{path: string, thumb_path: ?string}|null
     */
    private function storeOriginalRaster(string $binary, string $directory, string $basename): ?array
    {
        $ext = $this->detectRasterExtension($binary);
        if ($ext === null) {
            return null;
        }

        $path = $directory.'/'.$basename.'.'.$ext;
        try {
            Storage::disk('public')->put($path, $binary);
        } catch (\Throwable $e) {
            Log::warning('Screenshot original-bytes write failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return Storage::disk('public')->exists($path)
            ? ['path' => $path, 'thumb_path' => null]
            : null;
    }

    private function placeholderRaster(string $label): ?string
    {
        if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
            $width = 1280;
            $height = 720;
            $img = imagecreatetruecolor($width, $height);
            $bg = imagecolorallocate($img, 241, 245, 249);
            $bar = imagecolorallocate($img, 226, 232, 240);
            $text = imagecolorallocate($img, 100, 116, 139);
            imagefilledrectangle($img, 0, 0, $width, $height, $bg);
            imagefilledrectangle($img, 0, 0, $width, 56, $bar);
            imagestring($img, 5, (int) (($width - (strlen($label) * 9)) / 2), (int) ($height / 2 - 8), $label, $text);

            ob_start();
            $ok = imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);

            if ($ok && is_string($data) && $data !== '') {
                return $data;
            }
        }

        return $this->solidPngBytes(640, 360, [241, 245, 249]);
    }

    /**
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private function solidPngBytes(int $width, int $height, array $rgb): string
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $pixel = pack('C3', $rgb[0] & 255, $rgb[1] & 255, $rgb[2] & 255);
        $raw = str_repeat("\x00".$pixel.str_repeat($pixel, $width - 1), $height);
        $ihdr = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
        $deflate = function_exists('zlib_encode')
            ? zlib_encode($raw, ZLIB_ENCODING_DEFLATE, 6)
            : gzcompress($raw, 6);

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $ihdr)
            .$chunk('IDAT', $deflate)
            .$chunk('IEND', '');
    }

    private function detectRasterExtension(string $binary): ?string
    {
        if ($this->looksLikeJpeg($binary)) {
            return 'jpg';
        }
        if ($this->looksLikePng($binary)) {
            return 'png';
        }
        if ($this->looksLikeWebp($binary)) {
            return 'webp';
        }

        return null;
    }

    /**
     * True when $binary is a real JPEG/PNG, not JPEG-magic garbage.
     */
    protected function isDecodableRasterImage(string $binary, string $ext): bool
    {
        if ($binary === '') {
            return false;
        }

        if (function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($binary);

            return is_array($info)
                && isset($info[0], $info[1])
                && (int) $info[0] > 0
                && (int) $info[1] > 0;
        }

        return match (strtolower($ext)) {
            'jpg', 'jpeg' => $this->looksLikeJpeg($binary),
            'png' => $this->looksLikePng($binary),
            default => false,
        };
    }

    protected function looksLikeJpeg(string $binary): bool
    {
        if (strlen($binary) < 100) {
            return false;
        }

        if (! str_starts_with($binary, "\xff\xd8\xff")) {
            return false;
        }

        return str_ends_with($binary, "\xff\xd9");
    }

    protected function looksLikeWebp(string $binary): bool
    {
        return strlen($binary) >= 16
            && str_starts_with($binary, 'RIFF')
            && str_contains(substr($binary, 0, 16), 'WEBP');
    }

    protected function looksLikePng(string $binary): bool
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (! str_starts_with($binary, $signature) || strlen($binary) < 67) {
            return false;
        }

        if (substr($binary, 12, 4) !== 'IHDR') {
            return false;
        }

        return str_contains($binary, 'IEND');
    }

    /**
     * Convert a staff-uploaded cover (JPEG/PNG/WebP) to WebP on the public disk.
     * Returns null for GIF (keep animation) or when no encoder can re-encode.
     * Callers that must still persist the file use storeSafePublicImage().
     */
    public function storeUploadedImageAsWebp(UploadedFile $file, string $directory = 'sites'): ?string
    {
        $sourcePath = $file->getRealPath() ?: $file->getPathname();
        if (! is_string($sourcePath) || $sourcePath === '' || ! is_file($sourcePath)) {
            return null;
        }

        $binary = (string) file_get_contents($sourcePath);
        if ($binary === '') {
            return null;
        }

        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        if ($ext === 'gif') {
            return null;
        }

        $webp = $this->toWebp($binary, (int) config('site_enrichment.screenshots.quality', 82));
        if ($webp === null || $webp === '') {
            return null;
        }

        $directory = trim($directory, '/');
        $stem = Str::slug((string) pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME));
        $basename = ($stem !== '' ? $stem : 'site').'-'.Str::lower(Str::random(8));
        $path = $directory.'/'.$basename.'.webp';

        $disk = Storage::disk('public');
        try {
            $disk->put($path, $webp);
        } catch (\Throwable $e) {
            Log::warning('Staff cover WebP write failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $disk->exists($path) ? $path : null;
    }

    private static function gdWebpAvailable(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    private static function imagickWebpAvailable(): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $formats = array_map('strtoupper', \Imagick::queryFormats('WEBP') ?: []);

            return in_array('WEBP', $formats, true);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function cwebpBinary(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }
        $resolved = true;

        $configured = trim((string) config('site_enrichment.screenshots.cwebp_path', ''));
        $candidates = array_values(array_filter([
            $configured,
            '/usr/bin/cwebp',
            '/usr/local/bin/cwebp',
        ]));

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $dir = trim($dir);
            if ($dir !== '') {
                $candidates[] = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'cwebp';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
                $path = $candidate;

                return $path;
            }
        }

        return null;
    }

    private function toWebpViaGd(string $binary, int $quality): ?string
    {
        if (! self::gdWebpAvailable()) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        @imagealphablending($image, true);
        @imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, $quality);
        imagedestroy($image);
        $data = ob_get_clean();

        return $ok && is_string($data) && $data !== '' && str_starts_with($data, 'RIFF') ? $data : null;
    }

    private function toWebpViaImagick(string $binary, int $quality): ?string
    {
        if (! self::imagickWebpAvailable()) {
            return null;
        }

        try {
            $image = new \Imagick;
            $image->readImageBlob($binary);
            if ($image->getNumberImages() > 1) {
                $image->clear();
                $image->destroy();

                return null;
            }
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);
            $blob = $image->getImageBlob();
            $image->clear();
            $image->destroy();

            return is_string($blob) && $blob !== '' && str_starts_with($blob, 'RIFF') ? $blob : null;
        } catch (\Throwable $e) {
            Log::notice('Imagick WebP encode skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function toWebpViaCwebp(string $binary, int $quality, ?int $targetWidth = null): ?string
    {
        $encoder = self::cwebpBinary();
        if ($encoder === null) {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'cmbop-webp-in-');
        $out = tempnam(sys_get_temp_dir(), 'cmbop-webp-out-');
        if (! is_string($in) || ! is_string($out)) {
            return null;
        }

        $outWebp = $out.'.webp';
        try {
            if (file_put_contents($in, $binary) === false) {
                return null;
            }

            $command = [$encoder, '-quiet', '-q', (string) $quality];
            if ($targetWidth !== null && $targetWidth > 0) {
                $command[] = '-resize';
                $command[] = (string) max(1, $targetWidth);
                $command[] = '0';
            }
            $command[] = $in;
            $command[] = '-o';
            $command[] = $outWebp;

            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (! is_resource($process)) {
                return null;
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = proc_close($process);
            if ($status !== 0 || ! is_file($outWebp)) {
                return null;
            }

            $data = (string) file_get_contents($outWebp);

            return $data !== '' && str_starts_with($data, 'RIFF') ? $data : null;
        } catch (\Throwable $e) {
            Log::notice('cwebp encode skipped', ['error' => $e->getMessage()]);

            return null;
        } finally {
            foreach ([$in, $out, $outWebp] as $tmp) {
                if (is_string($tmp) && $tmp !== '' && is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }
}
