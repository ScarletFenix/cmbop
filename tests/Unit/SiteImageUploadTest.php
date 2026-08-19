<?php

namespace Tests\Unit;

use App\Support\SiteImageUpload;
use Tests\TestCase;

class SiteImageUploadTest extends TestCase
{
    public function test_max_kilobytes_is_the_app_cap_not_php_ini(): void
    {
        $this->assertSame(10240, SiteImageUpload::APP_MAX_KILOBYTES);
        $this->assertSame(10240, SiteImageUpload::maxKilobytes());
        $this->assertSame(10, SiteImageUpload::maxMegabytesLabel());
        $this->assertGreaterThan(0, SiteImageUpload::phpUploadMaxKilobytes());
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

    public function test_public_screenshot_path_accepts_original_raster(): void
    {
        $this->assertSame(
            'site-screenshots/site-12-20260101120000.jpg',
            SiteImageUpload::publicScreenshotPath('site-screenshots/site-12-20260101120000.jpg', 12)
        );
        $this->assertSame(
            'site-screenshots/site-12-20260101120000.png',
            SiteImageUpload::publicScreenshotPath('site-screenshots/site-12-20260101120000.png', 12)
        );
        $this->assertNull(SiteImageUpload::publicScreenshotPath('site-screenshots/home-placeholder.webp'));
        $this->assertNull(SiteImageUpload::publicScreenshotPath('site-screenshots/site-99-shot.jpg', 12));
    }

    public function test_field_rules_for_uploads_use_the_10mb_app_cap(): void
    {
        $rules = SiteImageUpload::fieldRules(true);
        $this->assertIsString($rules);
        $this->assertStringContainsString('max:10240', $rules);
        $this->assertStringContainsString('mimes:jpeg,png,jpg,gif,webp', $rules);
    }
}
