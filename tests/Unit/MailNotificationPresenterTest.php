<?php

namespace Tests\Unit;

use App\Models\OrderItem;
use App\Models\User;
use App\Support\LegalIdentity;
use App\Support\MailNotificationPresenter;
use Carbon\Carbon;
use Tests\TestCase;

class MailNotificationPresenterTest extends TestCase
{
    public function test_order_status_labels_use_sentence_case_in_review(): void
    {
        $this->assertSame('In review', MailNotificationPresenter::orderStatusLabel('review'));
        $this->assertSame('In review', MailNotificationPresenter::orderStatusLabel('in_review'));
        $this->assertSame('Processing', MailNotificationPresenter::orderStatusLabel('processing'));
        $this->assertSame('Paid', MailNotificationPresenter::paymentStatusLabel('paid'));
    }

    public function test_datetime_includes_explicit_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        if (app()->bound('transactional.mailTimezone')) {
            app()->forgetInstance('transactional.mailTimezone');
        }
        $label = MailNotificationPresenter::formatDateTime('2026-11-07 12:00:00');

        $this->assertStringContainsString('2026', $label);
        $this->assertMatchesRegularExpression('/^7 Nov 2026, 12:00 (UTC|GMT)$/', $label);
    }

    public function test_datetime_uses_recipient_timezone_when_present(): void
    {
        $user = new User;
        $user->setAttribute('timezone', 'Asia/Karachi');
        $label = MailNotificationPresenter::formatDateTime('2026-11-07 12:00:00', $user);

        $this->assertMatchesRegularExpression('/7 Nov 2026, \d{2}:\d{2} PKT/', $label);
    }

    public function test_live_url_rejects_unsafe_and_non_http_values(): void
    {
        $this->assertSame('https://example.com/post', MailNotificationPresenter::safeLiveUrl('https://example.com/post'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('javascript:alert(1)'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl(''));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('/relative/path'));
        $this->assertNull(MailNotificationPresenter::safeLiveUrl('not-a-url'));
    }

    public function test_auto_approve_deadline_uses_configured_window(): void
    {
        config(['orders.auto_approve_hours' => 72, 'app.timezone' => 'UTC']);
        $item = new OrderItem;
        $item->live_url_submitted_at = Carbon::parse('2026-11-01 12:00:00', 'UTC');

        $at = MailNotificationPresenter::autoApproveDeadlineAt($item);
        $this->assertNotNull($at);
        $this->assertSame('2026-11-04 12:00:00', $at->timezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertFalse(MailNotificationPresenter::dofollowVerified($item));
        $this->assertSame('example.com/post', MailNotificationPresenter::displayUrl('https://example.com/post'));
        $this->assertSame('news.example', MailNotificationPresenter::bareHost('https://www.news.example/path'));
    }

    public function test_legal_identity_normalizes_partners_with_wording(): void
    {
        $this->assertTrue(LegalIdentity::isMisleadingPartnerWording('SEOLinkBuildings Partners with (Teqno LTD)'));
        $this->assertSame(
            'Teqno Ltd trading as SEOLinkBuildings',
            LegalIdentity::normalizeLegalName('SEOLinkBuildings Partners with (Teqno LTD)')
        );
        $this->assertStringContainsString('trading name of Teqno Ltd', LegalIdentity::footer());
        $this->assertStringContainsString('17392540', LegalIdentity::footer());
    }
}
