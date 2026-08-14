<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ContentUploadService;
use App\Support\PhpIniSize;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PhpIniSizeTest extends TestCase
{
    public function test_parses_ini_size_units(): void
    {
        $this->assertSame(2048, PhpIniSize::toKilobytes('2M'));
        $this->assertSame(16 * 1024, PhpIniSize::toKilobytes('16M'));
        $this->assertSame(64 * 1024, PhpIniSize::toKilobytes('64M'));
        $this->assertSame(1024, PhpIniSize::toKilobytes('1024K'));
        $this->assertNull(PhpIniSize::toKilobytes('0'));
        $this->assertSame(10, PhpIniSize::megabytesLabel(10240));
        $this->assertSame(2, PhpIniSize::megabytesLabel(2048));
    }

    public function test_php_size_rejected_message_does_not_blame_article_cap_when_php_is_lower(): void
    {
        $service = app(ContentUploadService::class);
        $cfg = ['max_kilobytes' => 10240];
        $phpKb = $service->phpUploadMaxKilobytes();
        $appKb = $service->effectiveMaxKilobytes($cfg);
        $message = $service->phpSizeRejectedMessage($cfg);

        $this->assertSame(10240, $appKb);
        $this->assertSame(10240, $service->effectiveMaxKilobytes(['max_kilobytes' => 51200]));
        $this->assertStringContainsString('Please try again', $message);
        $this->assertStringNotContainsString('MB', $message);

        $this->assertStringNotContainsString('upload_max_filesize', $message);
        $this->assertStringNotContainsString('hosting PHP settings', $message);
        $this->assertStringNotContainsString('server PHP still allows only', $message);
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringContainsString('Please try again', $message);
        $this->assertStringNotContainsString('under 10 MB', $message);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
        $underCap = $service->phpSizeRejectedMessage($cfg, 5400000);
        $this->assertStringContainsString('Please try again', $underCap);
        $this->assertStringNotContainsString('under 10 MB', $underCap);
        $overCap = $service->phpSizeRejectedMessage($cfg, 12 * 1024 * 1024);
        $this->assertStringContainsString('That file is over the 10 MB limit', $overCap);
        $this->assertStringContainsString('JPG', $service->phpImageRejectedMessage());
        $this->assertStringNotContainsString('.docx', $service->phpImageRejectedMessage());
        $uploaded = $service->uploadValidationMessages($cfg)['file.uploaded'] ?? '';
        $this->assertStringContainsString('Please try again', $uploaded);
        $this->assertStringNotContainsString('under 10 MB', $uploaded);

        if ($phpKb < $appKb) {
            $this->assertTrue($service->phpLimitBlocksArticleCap($cfg));
        }
    }

    public function test_rejected_upload_uses_content_length_when_php_stripped_the_file(): void
    {
        $service = app(ContentUploadService::class);
        $cfg = ['max_kilobytes' => 10240];
        $this->assertTrue($service->contentLengthLooksLikeStrippedUpload(6 * 1024 * 1024));
        $this->assertFalse($service->contentLengthLooksLikeStrippedUpload(1024));

        $message = $service->rejectedUploadMessage(null, $cfg, 6 * 1024 * 1024);
        $this->assertIsString($message);
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringNotContainsString('upload_max_filesize', $message);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
        $this->assertNull($service->rejectedUploadMessage(null, $cfg, 1024));

        $imageMessage = $service->rejectedImageUploadMessage(null, 3 * 1024 * 1024);
        $this->assertIsString($imageMessage);
        $this->assertStringContainsString('image could not be uploaded', $imageMessage);
        $this->assertStringNotContainsString('.docx', $imageMessage);
        $this->assertNull($service->rejectedImageUploadMessage(null, 1024));

        $noLength = $service->rejectedUploadMessage(null, $cfg, null, 5 * 1024 * 1024);
        $this->assertIsString($noLength);
        $this->assertStringContainsString('The article could not be uploaded', $noLength);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $noLength);
        $this->assertNull($service->rejectedUploadMessage(null, $cfg, 0, null));

        $overCap = $service->rejectedUploadMessage(null, $cfg, null, 12 * 1024 * 1024);
        $this->assertStringContainsString('That file is over the 10 MB limit', $overCap);
    }

    public function test_upload_byte_hints_use_query_when_header_is_zero_or_junk(): void
    {
        $service = app(ContentUploadService::class);

        $zeroHeader = Request::create('/advertiser/content-library/upload?client_bytes=5400000', 'POST', [], [], [], [
            'HTTP_X_UPLOAD_BYTES' => '0',
            'CONTENT_LENGTH' => '0',
        ]);
        [, $fromZero] = $service->uploadByteHints($zeroHeader);
        $this->assertSame(5400000, $fromZero);

        $junkHeader = Request::create('/advertiser/content-library/upload?client_bytes=5400000', 'POST', [], [], [], [
            'HTTP_X_UPLOAD_BYTES' => 'not-a-number',
        ]);
        [, $fromJunk] = $service->uploadByteHints($junkHeader);
        $this->assertSame(5400000, $fromJunk);

        $both = Request::create('/advertiser/content-library/upload?client_bytes=1000', 'POST', [], [], [], [
            'HTTP_X_UPLOAD_BYTES' => '5400000',
        ]);
        [, $fromMax] = $service->uploadByteHints($both);
        $this->assertSame(5400000, $fromMax);

        $fromBody = Request::create('/advertiser/content-library/upload', 'POST', [
            'client_bytes' => '5400000',
        ], [], [], [
            'HTTP_X_UPLOAD_BYTES' => '0',
            'CONTENT_LENGTH' => '0',
        ]);
        [, $fromForm] = $service->uploadByteHints($fromBody);
        $this->assertSame(5400000, $fromForm);
    }

    public function test_unknown_php_upload_error_is_not_labeled_as_size(): void
    {
        $service = app(ContentUploadService::class);
        $path = sys_get_temp_dir().'/ext-'.uniqid('', true).'.docx';
        file_put_contents($path, 'x');
        $file = new UploadedFile(
            $path,
            'article.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            UPLOAD_ERR_EXTENSION,
            true
        );

        $message = $service->rejectedUploadMessage($file, ['max_kilobytes' => 10240]);
        @unlink($path);

        $this->assertIsString($message);
        $this->assertStringContainsString('Please try again', $message);
        $this->assertStringNotContainsString('under 10 MB', $message);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
    }
}
