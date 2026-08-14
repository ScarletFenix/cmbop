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

    public function test_normalize_stored_path_accepts_sites_covers(): void
    {
        $this->assertSame('sites/existing-cover.webp', SiteImageUpload::normalizeStoredPath('sites/existing-cover.webp'));
        $this->assertSame('sites/existing.jpg', SiteImageUpload::normalizeStoredPath('/sites/existing.jpg'));
        $this->assertSame('sites/nested/cover.PNG', SiteImageUpload::normalizeStoredPath('sites/nested/cover.PNG'));
    }

    public function test_normalize_stored_path_rejects_unsafe_values(): void
    {
        $this->assertNull(SiteImageUpload::normalizeStoredPath(''));
        $this->assertNull(SiteImageUpload::normalizeStoredPath('not-a-path'));
        $this->assertNull(SiteImageUpload::normalizeStoredPath('sites/../secret.webp'));
        $this->assertNull(SiteImageUpload::normalizeStoredPath('https://evil.example/cover.webp'));
        $this->assertNull(SiteImageUpload::normalizeStoredPath('sites/cover.pdf'));
    }

    public function test_field_rules_allow_stored_path_when_no_file(): void
    {
        $rules = SiteImageUpload::fieldRules(false);
        $this->assertIsArray($rules);
        $this->assertContains('nullable', $rules);
        $this->assertContains('regex:'.SiteImageUpload::STORED_PATH_REGEX, $rules);
    }
}
