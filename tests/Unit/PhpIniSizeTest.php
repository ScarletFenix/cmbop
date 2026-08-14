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
        $this->assertStringContainsString('MB', $message);

        if ($phpKb < $appKb) {
            $this->assertTrue($service->phpLimitBlocksArticleCap($cfg));
            $this->assertStringContainsString('under the 10 MB article limit', $message);
            $this->assertStringContainsString('PHP upload limit', $message);
            $this->assertStringContainsString('upload_max_filesize', $message);
            $this->assertStringNotContainsString('That file is over the 10 MB limit', $message);
        } else {
            $this->assertStringContainsString('That file is over the 10 MB limit', $message);
        }
    }
}
