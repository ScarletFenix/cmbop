<?php

namespace Tests\Unit;

use App\Support\SiteImageUpload;
use Tests\TestCase;

class SiteImageUploadTest extends TestCase
{
    public function test_max_kilobytes_never_exceeds_app_cap_or_php_ini(): void
    {
        $this->assertSame(10240, SiteImageUpload::APP_MAX_KILOBYTES);
        $this->assertSame(
            min(SiteImageUpload::APP_MAX_KILOBYTES, SiteImageUpload::phpUploadMaxKilobytes()),
            SiteImageUpload::maxKilobytes()
        );
        $this->assertSame(
            max(1, (int) floor(SiteImageUpload::maxKilobytes() / 1024)),
            SiteImageUpload::maxMegabytesLabel()
        );
    }

    public function test_php_upload_max_kilobytes_is_positive(): void
    {
        $this->assertGreaterThan(0, SiteImageUpload::phpUploadMaxKilobytes());
    }
}
