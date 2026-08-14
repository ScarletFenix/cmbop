<?php

namespace Tests\Unit;

use App\Services\ContentUpload\ContentUploadService;
use App\Support\PhpIniSize;
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
        $this->assertStringContainsString('MB', $message);

        $this->assertStringNotContainsString('upload_max_filesize', $message);
        $this->assertStringNotContainsString('hosting PHP settings', $message);
        $this->assertStringNotContainsString('server PHP still allows only', $message);
        $this->assertStringContainsString('The article could not be uploaded', $message);
        $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
        $this->assertStringContainsString('JPG', $service->phpImageRejectedMessage());
        $this->assertStringNotContainsString('.docx', $service->phpImageRejectedMessage());

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
}
