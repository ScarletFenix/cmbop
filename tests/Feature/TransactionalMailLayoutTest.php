<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Mail\LiveUrlSubmitted;
use App\Mail\OrderPaymentConfirmed;
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
        $this->assertStringContainsString('Article live', $html);
        $this->assertStringContainsString('Approve article', $html);
        $this->assertStringContainsString('Request corrections', $html);
        $this->assertStringContainsString('Your payment stays protected until you approve the live article', $html);
        $this->assertStringContainsString('No action by the deadline? The order is approved automatically.', $html);
        $this->assertStringContainsString('Layout Mag', $html);
        $this->assertStringContainsString('href="https://layout-mag.example/guest-post"', $html);
        $this->assertStringContainsString('>https://layout-mag.example/guest-post</a>', $html);
        $this->assertStringContainsString('European guest posts', $html);
        $this->assertStringContainsString('href="https://advertiser.example/offer"', $html);
        $this->assertStringContainsString('#ORD-LAYOUT-1', $html);
        $this->assertStringContainsString('Published', $html);
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
        $this->assertStringNotContainsString('Dofollow verified', $html);
        $this->assertStringNotContainsString('Unsubscribe', $html);
        $this->assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M \((UTC|GMT|PKT)\)/', $html);
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
        $this->assertStringContainsString('Order placed', $html);
        $this->assertStringContainsString('Placed', $html);
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringNotContainsString('>Writing<', $html);
        $this->assertStringContainsString('#ORD-LAYOUT-1', $html);
        $this->assertStringContainsString('REF-LAYOUT-1', $html);
        $this->assertStringContainsString('€120.00', $html);
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

        $this->assertStringContainsString('Order placed', $html);
        $this->assertStringContainsString('New order created', $html);
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringNotContainsString('Hello Ada', $html);
        $this->assertStringNotContainsString('Jules', $html);
    }

    public function test_weekly_digest_uses_shared_footer_and_site_cards(): void
    {
        $html = EmailCatalog::makeMailable('weekly_new_sites')?->render() ?? '';
        $this->assertNotSame('', $html);
        $this->assertStringContainsString('https://seolinkbuildings.com/assets/img/logo1.png', $html);
        $this->assertStringContainsString('New sites', $html);
        $this->assertStringContainsString('View site', $html);
        $this->assertStringContainsString('from €', $html);
        $this->assertStringContainsString('Hello,', $html);
        $this->assertStringContainsString('Matched on: Marketing · United Kingdom', $html);
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
        $this->assertStringContainsString('Wallet top-up', $html);
        $this->assertStringContainsString('€50.00', $html);
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
        $this->assertFalse(TransactionalMailTheme::articleLivePayload([
            'order' => $this->order->fresh(['items.site']),
            'orderItem' => $this->item->fresh(['site']),
        ])['dofollow_verified']);
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

        $this->assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M \(PKT\)/', $html);
    }

    public function test_order_preview_fixture_item_price_matches_total(): void
    {
        $html = EmailCatalog::makeMailable('order_payment_confirmed')?->render() ?? '';
        $this->assertNotSame('', $html);
        $this->assertSame(2, substr_count($html, '€138.00'));
        $this->assertStringNotContainsString('€99.00', $html);
        $this->assertStringNotContainsString('Reference code', $html);
        $this->assertStringNotContainsString('Sample User', $html);
        $this->assertStringContainsString('Hello,', $html);
    }
}
