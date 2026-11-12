<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Mail\LiveUrlSubmitted;
use App\Mail\OrderAccepted;
use App\Mail\OrderPaymentConfirmed;
use App\Mail\OrderRejected;
use App\Mail\OrderStatusChanged;
use App\Mail\WeeklyNewSitesDigestMail;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\EmailCatalog;
use App\Support\LegalIdentity;
use App\Support\TransactionalMailTheme;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TransactionalMailLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    private Order $order;

    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $this->advertiser = $this->userWithRole('advertiser', ['name' => 'Ada']);
        $this->publisher = $this->userWithRole('publisher', ['name' => 'Jules']);
        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Layout Mag',
            'site_url' => 'https://layout-mag.example',
            'domain' => 'layout-mag.example',
            'da' => 40,
            'dr' => 38,
            'traffic' => 8000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 120,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Transactional mail layout fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);
        $this->order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-LAYOUT-1',
            'reference_code' => 'REF-LAYOUT-1',
            'subtotal' => 120,
            'tax' => 0,
            'total_amount' => 120,
            'status' => 'review',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);
        $this->item = OrderItem::create([
            'order_id' => $this->order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 120,
            'status' => 'review',
            'live_url' => 'https://layout-mag.example/guest-post',
            'live_url_submitted_at' => now(),
            'anchor_text' => 'European guest posts',
            'target_url' => 'https://advertiser.example/offer',
            'homepage_days' => 7,
            'homepage_price' => 25,
            'social_channels' => ['facebook', 'x'],
        ]);
    }

    private function userWithRole(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_article_live_email_uses_shared_layout_and_review_deadline(): void
    {
        config(['orders.auto_approve_hours' => 72]);
        $html = (new LiveUrlSubmitted(
            $this->order->fresh(['user', 'items.site']),
            $this->item->fresh(['site']),
            $this->site,
            $this->item->live_url,
        ))->render();

        $this->assertStringContainsString('https://seolinkbuildings.com/assets/img/logo1.png', $html);
        $this->assertTrue(
            str_contains($html, 'border-bottom:3px solid #3faeb2')
            || str_contains($html, 'border-bottom: 3px solid #3faeb2'),
            'Header should use the 3px accent border'
        );
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('ARTICLE LIVE', $html);
        $this->assertStringContainsString('Your article is live on layout-mag.example', $html);
        $this->assertStringContainsString('Please check it and approve, or ask for corrections.', $html);
        $this->assertStringNotContainsString('Your article is live — #ORD-LAYOUT-1', $html);
        $this->assertStringContainsString('Approve article', $html);
        $this->assertStringContainsString('Request corrections', $html);
        $this->assertStringContainsString('mail-btn-row', $html);
        $this->assertStringContainsString('mail-btn-cell', $html);
        $this->assertStringContainsString('Your payment stays protected', $html);
        $this->assertStringContainsString('until you approve the live article', $html);
        $this->assertStringContainsString('No action by the deadline? The order is approved automatically.', $html);
        $this->assertStringContainsString('layout-mag.example', $html);
        $this->assertStringNotContainsString('Layout Mag (layout-mag.example)', $html);
        $this->assertStringContainsString('href="https://layout-mag.example/guest-post"', $html);
        $this->assertStringContainsString('>layout-mag.example/guest-post</a>', $html);
        $this->assertStringContainsString('Anchor text:', $html);
        $this->assertStringContainsString('European guest posts', $html);
        $this->assertStringContainsString('href="https://advertiser.example/offer"', $html);
        $this->assertStringContainsString('#ORD-LAYOUT-1', $html);
        $this->assertStringContainsString('Published', $html);
        $this->assertStringContainsString('mail-progress-line', $html);
        $this->assertStringContainsString('mail-progress-current', $html);
        $this->assertStringContainsString('width:28px', $html);
        $this->assertStringContainsString('height:28px', $html);
        $this->assertTrue(
            str_contains($html, 'border-radius:50%')
            || str_contains($html, 'border-radius: 50%')
            || str_contains($html, 'border-radius:28px')
            || str_contains($html, 'border-radius: 28px'),
            'Progress steps should be true circles (50% or 28px radius)'
        );
        $this->assertStringContainsString('mail-dofollow', $html);
        $this->assertStringContainsString('mail-dofollow-pill', $html);
        $this->assertTrue(
            str_contains($html, 'background-color:#dcfce7') || str_contains($html, 'background-color: #dcfce7'),
            'Light-mode Dofollow pill should keep the mint background'
        );
        $this->assertTrue(
            str_contains($html, 'color:#15803d') || str_contains($html, 'color: #15803d'),
            'Light-mode Dofollow pill should keep green text'
        );
        $this->assertTrue(
            str_contains($html, 'background-color: #14532d !important')
            || str_contains($html, 'background-color:#14532d !important'),
            'Dark-mode Dofollow pill should keep a green background'
        );
        $this->assertTrue(
            str_contains($html, 'color: #dcfce7 !important') || str_contains($html, 'color:#dcfce7 !important'),
            'Dark-mode Dofollow pill should keep light mint text'
        );
        $this->assertStringContainsString('[data-ogsc] .mail-dofollow-pill', $html);
        $this->assertStringContainsString('[data-ogsb] .mail-dofollow-pill', $html);
        $this->assertTrue(
            str_contains($html, 'word-break:normal') || str_contains($html, 'word-break: normal'),
            'Anchor URL should use word-break: normal'
        );
        $this->assertFalse(
            str_contains($html, 'word-break:break-all') || str_contains($html, 'word-break: break-all'),
            'Anchor URL should not use word-break: break-all'
        );
        $this->assertStringContainsString('advertiser.example/offer', $html);
        $this->assertStringContainsString('v:roundrect', $html);
        $this->assertTrue(
            str_contains($html, '2px solid #0b6266')
            || str_contains($html, '2px solid #0B6266')
            || str_contains($html, 'border-color:#0b6266'),
            'Current progress step should be a teal ring'
        );
        $this->assertStringContainsString('#b45309', $html);
        $this->assertStringContainsString('Questions? Just reply to this email', $html);
        $this->assertStringContainsString('support@seolinkbuildings.com', $html);
        $this->assertStringContainsString('European guest post marketplace', $html);
        $this->assertStringContainsString('Help center', $html);
        $this->assertStringContainsString('Notification settings', $html);
        $this->assertStringNotContainsString('Teqno', $html);
        $this->assertStringNotContainsString('17392540', $html);
        $this->assertStringNotContainsString(LegalIdentity::FOOTER, $html);
        $this->assertStringNotContainsString('Jules', $html);
        $this->assertStringNotContainsString('€120', $html);
        $this->assertStringNotContainsString('€25', $html);
        $this->assertStringContainsString('Dofollow verified', $html);
        $this->assertStringNotContainsString('Unsubscribe', $html);
        $this->assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2} (UTC|GMT|PKT)/', $html);
        $this->assertStringContainsString('mail-tick', $html);
        $this->assertStringContainsString('✓', $html);
        $this->assertStringNotContainsString('success-check.gif', $html);
        $this->assertFileExists(public_path('assets/img/email/success-check.gif'));
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringNotContainsString('Hello Ada', $html);
        $this->assertStringContainsString('Homepage placement', $html);
        $this->assertStringContainsString('7 days', $html);
        $this->assertStringContainsString('Social promotion', $html);
        $this->assertStringNotContainsString('Questions about this order belong on the order page.', $html);
        $this->assertStringNotContainsString('Live article on Layout Mag', $html);
        $this->assertStringContainsString('mail-logo-pill', $html);
        $this->assertStringContainsString('mail-progress-future', $html);
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringNotContainsString('>Writing<', $html);
    }

    public function test_order_placed_email_shows_progress_and_omits_company_details(): void
    {
        $this->order->update(['status' => 'pending']);
        $html = (new OrderPaymentConfirmed($this->order->fresh(['user', 'items'])))->render();

        $this->assertStringContainsString('https://seolinkbuildings.com/assets/img/logo1.png', $html);
        $this->assertStringContainsString('ORDER PLACED', $html);
        $this->assertStringContainsString('Order placed for layout-mag.example', $html);
        $this->assertStringContainsString('Placed', $html);
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringNotContainsString('>Writing<', $html);
        $this->assertStringContainsString('#ORD-LAYOUT-1', $html);
        $this->assertStringContainsString('REF-LAYOUT-1', $html);
        $this->assertStringContainsString('€120', $html);
        $this->assertStringNotContainsString('€120.00', $html);
        $this->assertStringContainsString('Paid on', $html);
        $this->assertStringNotContainsString('Order date', $html);
        $this->assertStringNotContainsString('Payment date', $html);
        $this->assertStringContainsString('View your content', $html);
        $this->assertStringNotContainsString('>Content<', $html);
        $this->assertEquals(1, substr_count($html, '>layout-mag.example<'));
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringNotContainsString('Hello Ada', $html);
        $this->assertStringNotContainsString('Best regards', $html);
        $this->assertStringNotContainsString('This is a confirmation that your payment has been received', $html);
        $this->assertStringContainsString('https://layout-mag.example', $html);
        $this->assertStringContainsString('Questions? Just reply to this email', $html);
        $this->assertStringNotContainsString('Teqno', $html);
        $this->assertStringNotContainsString('17392540', $html);
        $this->assertStringNotContainsString('Unsubscribe', $html);
        $this->assertGreaterThan(0, substr_count($html, 'width="600"') + substr_count($html, 'width:600px'));
    }

    public function test_order_accepted_email_omits_subtitle_duplicate(): void
    {
        $html = (new OrderAccepted(
            $this->order->fresh(['user', 'items']),
            $this->item->fresh(['site']),
            $this->site,
        ))->render();

        $this->assertStringContainsString('ACCEPTED', $html);
        $this->assertStringContainsString('layout-mag.example accepted your order', $html);
        $this->assertStringContainsString('The publisher accepted this order and work can begin.', $html);
        $this->assertStringNotContainsString('The publisher has accepted your order and will start working on it.', $html);
        $this->assertStringContainsString('width:28px', $html);
        $this->assertStringContainsString('v:roundrect', $html);
        $this->assertStringContainsString('Hello,', $html);
    }

    public function test_order_rejected_email_omits_subtitle_duplicate(): void
    {
        $html = (new OrderRejected(
            $this->order->fresh(['user', 'items']),
            $this->item->fresh(['site']),
            $this->site,
            'Does not fit the editorial calendar.',
        ))->render();

        $this->assertStringContainsString('REJECTED', $html);
        $this->assertStringContainsString('layout-mag.example did not accept your order', $html);
        $this->assertStringContainsString('This placement was not accepted. Browse other sites to continue.', $html);
        $this->assertStringNotContainsString('You can browse other publishers and place a new order.', $html);
        $this->assertStringContainsString('Hello,', $html);
    }

    public function test_created_order_status_email_is_order_placed_step(): void
    {
        $html = (new OrderStatusChanged(
            $this->order->fresh(['user', 'items.site']),
            $this->advertiser,
            'advertiser',
            'created',
            null,
            'pending',
        ))->render();

        $this->assertStringContainsString('ORDER PLACED', $html);
        $this->assertStringContainsString('Order placed for layout-mag.example', $html);
        $this->assertStringNotContainsString('New order created', $html);
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringNotContainsString('Hello Ada', $html);
        $this->assertStringNotContainsString('Jules', $html);
    }

    public function test_weekly_digest_uses_shared_footer_and_site_cards(): void
    {
        $html = EmailCatalog::makeMailable('weekly_new_sites')?->render() ?? '';
        $this->assertNotSame('', $html);
        $this->assertStringContainsString('https://seolinkbuildings.com/assets/img/logo1.png', $html);
        $this->assertStringContainsString('NEW SITES', $html);
        $this->assertStringContainsString('✦', $html);
        $this->assertStringContainsString('View site', $html);
        $this->assertStringContainsString('from €90', $html);
        $this->assertStringContainsString('from €140', $html);
        $this->assertStringNotContainsString('from €90.00', $html);
        $this->assertStringNotContainsString('from €140.00', $html);
        $this->assertStringContainsString('align="center"', $html);
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringContainsString('Matched on: Marketing · United Kingdom', $html);
        $this->assertStringNotContainsString('Here are 2 newly listed sites that match placements you have paid for.', $html);
        $this->assertStringNotContainsString('Preview card for', $html);
        $this->assertStringContainsString('Arial, Helvetica, sans-serif', $html);
        $this->assertStringContainsString('Unsubscribe', $html);
        $this->assertStringContainsString('Help center', $html);
        $this->assertStringContainsString('Notification settings', $html);
        $this->assertStringNotContainsString('Teqno', $html);
        $this->assertStringNotContainsString('17392540', $html);
        $this->assertStringNotContainsString('Dofollow verified', $html);
    }

    public function test_wallet_top_up_uses_wallet_pill(): void
    {
        $deposit = DepositRequest::create([
            'user_id' => $this->advertiser->id,
            'amount' => 50,
            'status' => 'approved',
            'payment_method' => 'card',
            'reference_code' => 'DEP-LAYOUT',
        ]);
        $html = (new DepositApproved($deposit->fresh('user')))->render();
        $this->assertStringContainsString('WALLET TOP-UP', $html);
        $this->assertStringContainsString('€50', $html);
        $this->assertStringNotContainsString('€50.00', $html);
        $this->assertStringNotContainsString('Teqno Ltd', $html);
    }

    public function test_plain_text_versions_exist_for_markdown_and_digest(): void
    {
        $mail = new LiveUrlSubmitted(
            $this->order->fresh(['user', 'items.site']),
            $this->item->fresh(['site']),
            $this->site,
            $this->item->live_url,
        );
        $built = $mail->build();
        $this->assertNotNull($built);

        $digest = EmailCatalog::makeMailable('weekly_new_sites');
        $this->assertInstanceOf(WeeklyNewSitesDigestMail::class, $digest);
        $digest->build();
        $this->assertNotEmpty($digest->textView);

        $text = view('emails.advertiser.weekly-new-sites-digest-text', [
            'firstName' => 'Ada',
            'cards' => $digest->cards,
            'browseLinks' => $digest->browseLinks,
            'unsubscribeUrl' => 'https://example.test/unsub',
            'preferencesUrl' => 'https://example.test/prefs',
            'brand' => ['name' => 'SEOLinkBuildings'],
            'supportLine' => 'If you have questions, contact support at support@seolinkbuildings.com.',
        ])->render();
        $this->assertStringContainsString('from €', $text);
        $this->assertStringContainsString('View site:', $text);
        $this->assertStringContainsString('Unsubscribe:', $text);
        $this->assertStringNotContainsString('Teqno', $text);
    }

    public function test_local_preview_command_writes_html(): void
    {
        $dir = storage_path('app/testing/mail-previews');
        $this->assertSame(0, Artisan::call('mail:render-previews', [
            'keys' => ['live_url_submitted', 'order_payment_confirmed', 'weekly_new_sites'],
            '--dir' => $dir,
        ]));
        $this->assertFileExists($dir.'/live_url_submitted.html');
        $this->assertFileExists($dir.'/order_payment_confirmed.html');
        $this->assertFileExists($dir.'/weekly_new_sites.html');
        $live = (string) file_get_contents($dir.'/live_url_submitted.html');
        $this->assertStringContainsString('Approve article', $live);
    }

    public function test_theme_does_not_invent_dofollow_from_listing_type(): void
    {
        $payload = TransactionalMailTheme::articleLivePayload([
            'order' => $this->order->fresh(['items.site']),
            'orderItem' => $this->item->fresh(['site']),
        ]);
        $this->assertFalse($payload['dofollow_verified']);
        $this->assertTrue($payload['show_dofollow_pill']);
    }

    public function test_article_live_subject_names_the_site_not_the_order_number(): void
    {
        $mail = (new LiveUrlSubmitted(
            $this->order->fresh(['user', 'items.site']),
            $this->item->fresh(['site']),
            $this->site,
            $this->item->live_url,
        ))->build();

        $this->assertSame('Your article is live on layout-mag.example – please review', $mail->subject);
    }

    public function test_review_deadline_uses_recipient_timezone_when_present(): void
    {
        $order = $this->order->fresh(['user', 'items.site']);
        $order->user->setAttribute('timezone', 'Asia/Karachi');
        $html = (new LiveUrlSubmitted(
            $order,
            $this->item->fresh(['site']),
            $this->site,
            $this->item->live_url,
        ))->render();

        $this->assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d{2}:\d{2} PKT/', $html);
    }

    public function test_order_preview_fixture_item_price_matches_total(): void
    {
        $html = EmailCatalog::makeMailable('order_payment_confirmed')?->render() ?? '';
        $this->assertNotSame('', $html);
        $this->assertSame(1, substr_count($html, '€138'));
        $this->assertStringNotContainsString('€138.00', $html);
        $this->assertStringNotContainsString('€99.00', $html);
        $this->assertStringContainsString('Paid on', $html);
        $this->assertStringContainsString('View your content', $html);
        $this->assertStringNotContainsString('Reference code', $html);
        $this->assertStringNotContainsString('Sample User', $html);
        $this->assertStringContainsString('Hello,', $html);
    }
}
