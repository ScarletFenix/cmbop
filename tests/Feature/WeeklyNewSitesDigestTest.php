<?php

namespace Tests\Feature;

use App\Mail\WeeklyNewSitesDigestMail;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\WeeklyNewSitesInterest;
use App\Models\WeeklyNewSitesRecipient;
use App\Models\WeeklyNewSitesSend;
use App\Models\WeeklyNewSitesToken;
use App\Services\WeeklyNewSites\PermissionGate;
use App\Services\WeeklyNewSites\Watermark;
use App\Support\EmailCatalog;
use App\Support\WeeklyNewSitesLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeeklyNewSitesDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'mail.default' => 'array',
            'email_notifications.queue_connection' => 'sync',
        ]);
        app(Watermark::class)->replace(now()->subDay());
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function site(User $publisher, array $extra = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'WNS '.uniqid(),
            'site_url' => 'https://wns-'.uniqid().'.example',
            'domain' => 'wns-'.uniqid().'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 12000,
            'metrics_fetched_at' => now()->subDay(),
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'price' => 120,
            'turnaround_time' => '3days',
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Weekly digest fixture',
            'verified' => true,
            'active' => true,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $orderExtra
     * @param  array<string, mixed>  $itemExtra
     */
    private function paidOrderFor(User $advertiser, Site $site, array $orderExtra = [], array $itemExtra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-WNS-'.uniqid(),
            'reference_code' => 'REF-WNS-'.uniqid(),
            'subtotal' => 120,
            'tax' => 0,
            'total_amount' => 120,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(10),
        ], $orderExtra));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 120,
            'publisher_price' => 100,
            'content_link' => 'https://example.com/article.docx',
        ], $itemExtra));

        return $order->fresh('items');
    }

    private function grantConsent(User $user, bool $marketing = true): UserConsent
    {
        return UserConsent::create([
            'user_id' => $user->id,
            'terms_accepted' => true,
            'marketing_consent' => $marketing,
            'newsletter_consent' => false,
            'consented_at' => now(),
        ]);
    }

    private function eligibleAdvertiser(): array
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->grantConsent($advertiser, true);
        $ordered = $this->site($publisher, [
            'country' => 'us',
            'countries' => ['us'],
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'first_catalogued_at' => now()->subMonths(2),
        ]);
        $this->paidOrderFor($advertiser, $ordered);

        return [$publisher, $advertiser, $ordered];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function newMatch(User $publisher, array $extra = []): Site
    {
        return $this->site($publisher, array_merge([
            'country' => 'us',
            'countries' => ['us'],
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'first_catalogued_at' => now()->subHour(),
            'domain' => 'new-'.uniqid().'.example',
            'site_url' => 'https://new-'.uniqid().'.example',
        ], $extra));
    }

    public function test_feature_defaults_to_enabled_without_enabling_unrelated_reminders(): void
    {
        $this->assertTrue((bool) config('email_notifications.types.weekly_new_sites.default_enabled'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('weekly_new_sites'));
        $this->assertFalse((bool) config('reminders.abandoned_cart.enabled'));
        $this->assertSame(WeeklyNewSitesDigestMail::class, config('email_notifications.types.weekly_new_sites.mailable'));
        $this->assertArrayHasKey('weekly_new_sites', EmailCatalog::templates());
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'weekly_new_sites'))
            ->assertOk()
            ->assertSee('New sites in your niches', false)
            ->assertSee('SEOLinkBuildings', false)
            ->assertSee('Notification settings', false)
            ->assertSee('Unsubscribe', false)
            ->assertDontSee('Preview card for', false)
            ->assertSee('Matched on: Marketing · United Kingdom', false)
            ->assertSee('Hello,', false)
            ->assertDontSee('Teqno Ltd', false)
            ->assertDontSee('17392540', false)
            ->assertDontSee('trading as', false)
            ->assertDontSee('20 Wenlock Road', false);
    }

    public function test_schedule_registers_monday_karachi_command(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("command('emails:send-weekly-new-sites-digest')", $bootstrap);
        $this->assertStringContainsString("timezone('Asia/Karachi')", $bootstrap);
        $this->assertStringContainsString('weekly_new_sites_digest.time', $bootstrap);
    }

    public function test_unknown_permission_is_not_granted_from_a_paid_order_alone(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        UserConsent::query()->where('user_id', $advertiser->id)->delete();
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
        $this->assertSame('unknown_permission', app(PermissionGate::class)->inspect($advertiser)['reason']);
    }

    public function test_recorded_marketing_consent_allows_a_digest(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, fn ($mail) => $mail->hasTo($advertiser->email));
    }

    public function test_pecr_existing_customer_with_consent_record_allows_send(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        UserConsent::query()->where('user_id', $advertiser->id)->update(['marketing_consent' => false]);
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, fn ($mail) => $mail->hasTo($advertiser->email));
        $this->assertSame(PermissionGate::BASIS_PECR, app(PermissionGate::class)->inspect($advertiser)['basis']);
    }

    public function test_global_marketing_unsub_blocks(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        EmailNotificationPreference::create([
            'user_id' => $advertiser->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
    }

    public function test_topic_unsub_blocks(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        EmailNotificationPreference::create([
            'user_id' => $advertiser->id,
            'preference_key' => 'weekly_new_sites',
            'enabled' => false,
        ]);
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
    }

    public function test_matches_exact_niche_country_pairs_not_cross_product(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $techDe = $this->site($publisher, [
            'country' => 'de',
            'countries' => ['de'],
            'category' => 'Technology',
            'categories' => ['Technology'],
            'first_catalogued_at' => now()->subMonths(2),
            'domain' => 'ordered-tech-de.example',
        ]);
        $this->paidOrderFor($advertiser, $techDe);

        $financeUs = $this->newMatch($publisher, [
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'country' => 'us',
            'domain' => 'finance-us-a.example',
        ]);
        $financeUs2 = $this->newMatch($publisher, [
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'country' => 'us',
            'domain' => 'finance-us-b.example',
        ]);
        $cross = $this->newMatch($publisher, [
            'category' => 'Marketing',
            'categories' => ['Marketing'],
            'country' => 'de',
            'countries' => ['de'],
            'domain' => 'cross-marketing-de.example',
        ]);
        $this->newMatch($publisher, [
            'category' => 'Technology',
            'categories' => ['Technology'],
            'country' => 'us',
            'domain' => 'cross-tech-us.example',
        ]);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) use ($financeUs, $financeUs2, $cross) {
            $domains = $mail->cards->pluck('domain')->all();
            $this->assertContains($financeUs->domain, $domains);
            $this->assertContains($financeUs2->domain, $domains);
            $this->assertNotContains($cross->domain, $domains);
            $this->assertNotContains('cross-tech-us.example', $domains);

            return true;
        });
    }

    public function test_managed_orders_infer_pairs(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->grantConsent($advertiser, true);
        $ordered = $this->site($publisher, [
            'country' => 'gb',
            'countries' => ['gb'],
            'category' => 'Health',
            'categories' => ['Health'],
            'first_catalogued_at' => now()->subMonths(3),
        ]);
        $this->paidOrderFor($advertiser, $ordered, [], [
            'fulfilment_source' => 'managed_by_slb',
        ]);
        $this->newMatch($publisher, [
            'country' => 'gb',
            'countries' => ['gb'],
            'category' => 'Health',
            'categories' => ['Health'],
            'domain' => 'health-gb-a.example',
        ]);
        $this->newMatch($publisher, [
            'country' => 'gb',
            'countries' => ['gb'],
            'category' => 'Health',
            'categories' => ['Health'],
            'domain' => 'health-gb-b.example',
        ]);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) {
            $domains = $mail->cards->pluck('domain')->all();
            $this->assertContains('health-gb-a.example', $domains);
            $this->assertContains('health-gb-b.example', $domains);

            return true;
        });
    }

    public function test_cancelled_and_refunded_orders_do_not_infer_pairs(): void
    {
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $this->grantConsent($advertiser, true);
        $cancelledSite = $this->site($publisher, [
            'category' => 'Finance',
            'categories' => ['Finance'],
            'first_catalogued_at' => now()->subMonths(2),
        ]);
        $this->paidOrderFor($advertiser, $cancelledSite, ['status' => 'cancelled', 'payment_status' => 'paid']);
        $refundedSite = $this->site($publisher, [
            'category' => 'Sports',
            'categories' => ['Sports'],
            'country' => 'de',
            'countries' => ['de'],
            'first_catalogued_at' => now()->subMonths(2),
        ]);
        $this->paidOrderFor($advertiser, $refundedSite, ['payment_status' => 'refunded', 'status' => 'completed']);

        $this->newMatch($publisher, ['category' => 'Finance', 'categories' => ['Finance'], 'domain' => 'fin-a.example']);
        $this->newMatch($publisher, ['category' => 'Finance', 'categories' => ['Finance'], 'domain' => 'fin-b.example']);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
    }

    public function test_zero_or_one_match_skips_two_sends_five_caps_and_carryover(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);

        $this->newMatch($publisher, ['domain' => 'only-one.example']);
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);

        $this->newMatch($publisher, ['domain' => 'second.example']);
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertQueued(WeeklyNewSitesDigestMail::class, fn (WeeklyNewSitesDigestMail $mail) => $mail->cards->count() === 2);

        Mail::fake();
        WeeklyNewSitesRecipient::query()->delete();
        WeeklyNewSitesSend::query()->delete();

        for ($i = 0; $i < 7; $i++) {
            $this->newMatch($publisher, ['domain' => 'cap-'.$i.'.example']);
        }
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) {
            $this->assertCount(5, $mail->cards);

            return true;
        });

        $sentDomains = WeeklyNewSitesSend::query()->pluck('domain')->all();
        $this->assertCount(5, $sentDomains);

        Mail::fake();
        Carbon::setTestNow(now()->addWeek());
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) use ($sentDomains) {
            foreach ($mail->cards as $card) {
                $this->assertNotContains($card['domain'], $sentDomains);
            }

            return $mail->cards->count() >= 2;
        });
        Carbon::setTestNow();
    }

    public function test_historical_catalogue_and_reapprovals_are_not_new(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $old = $this->newMatch($publisher, [
            'first_catalogued_at' => now()->subYears(1),
            'domain' => 'ancient.example',
        ]);
        $this->newMatch($publisher, ['domain' => 'fresh-a.example']);
        $reapproved = $this->newMatch($publisher, ['domain' => 'fresh-b.example']);
        $firstCatalogued = $reapproved->first_catalogued_at->copy();
        $reapproved->forceFill([
            'verified' => false,
            'active' => false,
        ])->save();
        $reapproved->forceFill([
            'verified' => true,
            'active' => true,
        ])->save();

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) use ($old, $reapproved, $firstCatalogued) {
            $domains = $mail->cards->pluck('domain')->all();
            $this->assertNotContains($old->domain, $domains);
            $this->assertContains('fresh-a.example', $domains);
            $this->assertContains($reapproved->domain, $domains);
            $this->assertTrue($reapproved->fresh()->first_catalogued_at->equalTo($firstCatalogued));

            return true;
        });
    }

    public function test_already_ordered_and_domain_duplicates_are_excluded(): void
    {
        [$publisher, $advertiser, $ordered] = $this->eligibleAdvertiser();
        $otherPublisher = $this->userWithRole('publisher');
        $this->newMatch($otherPublisher, [
            'domain' => $ordered->domain,
            'site_url' => 'https://'.$ordered->domain.'/copy',
        ]);
        $this->newMatch($publisher, ['domain' => 'unique-a.example']);
        $this->newMatch($publisher, ['domain' => 'unique-b.example']);
        $dup = $this->newMatch($otherPublisher, ['domain' => 'unique-a.example']);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) use ($ordered, $dup) {
            $domains = $mail->cards->pluck('domain')->all();
            $ids = $mail->cards->pluck('site_id')->all();
            $this->assertNotContains($ordered->domain, $domains);
            $this->assertContains('unique-a.example', $domains);
            $this->assertContains('unique-b.example', $domains);
            $this->assertCount(1, array_filter($domains, fn ($d) => $d === 'unique-a.example'));
            $this->assertNotContains($dup->id, $ids);

            return true;
        });
    }

    public function test_scheduler_overlap_does_not_duplicate_the_week(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        $this->assertCount(1, Mail::queued(WeeklyNewSitesDigestMail::class));
        $this->assertSame(1, WeeklyNewSitesRecipient::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_admin_off_and_unsub_after_queue_stop_send(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->newMatch($publisher, ['domain' => 'queued-a.example']);
        $this->newMatch($publisher, ['domain' => 'queued-b.example']);
        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        $queued = Mail::queued(WeeklyNewSitesDigestMail::class)->first();
        $this->assertInstanceOf(WeeklyNewSitesDigestMail::class, $queued);

        EmailNotificationSetting::query()->updateOrCreate(
            ['type' => 'weekly_new_sites'],
            ['enabled' => false]
        );
        EmailNotificationSetting::flushCache('weekly_new_sites');

        $this->assertNull($queued->send(app('mailer')));
        $this->assertSame('admin_disabled', $queued->suppressReason);

        EmailNotificationSetting::query()->updateOrCreate(
            ['type' => 'weekly_new_sites'],
            ['enabled' => true]
        );
        EmailNotificationSetting::flushCache('weekly_new_sites');

        EmailNotificationPreference::updateOrCreate(
            ['user_id' => $advertiser->id, 'preference_key' => 'weekly_new_sites'],
            ['enabled' => false]
        );

        $again = new WeeklyNewSitesDigestMail(
            $advertiser,
            $queued->cards,
            $queued->browseLinks,
            $queued->weekKey,
            $queued->recipientId,
        );
        $this->assertNull($again->send(app('mailer')));
        $this->assertSame('topic_unsubscribed', $again->suppressReason);
        $this->assertSame(
            WeeklyNewSitesRecipient::STATUS_CANCELLED,
            WeeklyNewSitesRecipient::query()->find($queued->recipientId)?->status
        );
    }

    public function test_email_uses_public_price_and_omits_private_fields(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->newMatch($publisher, ['domain' => 'priced-a.example', 'price' => 80]);
        $this->newMatch($publisher, ['domain' => 'priced-b.example', 'price' => 80]);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) use ($publisher) {
            $html = $mail->render();
            $this->assertStringContainsString('priced-a.example', $html);
            $this->assertStringContainsString('View site', $html);
            $this->assertStringContainsString('Browse matching sites', $html);
            $this->assertStringContainsString('SEOLinkBuildings', $html);
            $this->assertStringContainsString('Notification settings', $html);
            $this->assertStringContainsString('Unsubscribe', $html);
            $this->assertStringContainsString('support@seolinkbuildings.com', strtolower($html));
            $this->assertStringNotContainsString('Teqno', $html);
            $this->assertStringNotContainsString('17392540', $html);
            $this->assertStringNotContainsString('trading as', $html);
            $this->assertStringNotContainsString('Registered in England', $html);
            $this->assertStringNotContainsString('20 Wenlock Road', $html);
            $this->assertStringNotContainsString($publisher->email, $html);
            $this->assertStringNotContainsString('publisher_price', $html);
            $this->assertStringNotContainsString('platform_fee', $html);
            $this->assertStringNotContainsString('markup', $html);
            $this->assertStringNotContainsString('wallet', strtolower($html));
            $this->assertDoesNotMatchRegularExpression('/[😀-🙏]/u', $html);
            $this->assertStringNotContainsString('yellow', strtolower($html));
            $this->assertStringNotContainsString('#ffc', strtolower($html));
            $this->assertStringNotContainsString('#ed8', strtolower($html));
            $this->assertStringContainsString('#1a585e', $html);
            $text = view('emails.advertiser.weekly-new-sites-digest-text', [
                'firstName' => 'Ada',
                'cards' => $mail->cards,
                'browseLinks' => $mail->browseLinks,
                'unsubscribeUrl' => 'https://example.test/unsub',
                'preferencesUrl' => 'https://example.test/prefs',
                'brand' => ['name' => 'SEOLinkBuildings'],
                'supportLine' => 'If you have questions, contact support at support@seolinkbuildings.com.',
            ])->render();
            $this->assertStringContainsString('priced-a.example', $text);
            $this->assertStringContainsString('SEOLinkBuildings', $text);
            $this->assertStringContainsString('Notification settings:', $text);
            $this->assertStringContainsString('Unsubscribe:', $text);
            $this->assertStringNotContainsString('Teqno', $text);
            $this->assertStringNotContainsString('17392540', $text);
            $this->assertStringNotContainsString('20 Wenlock Road', $text);
            foreach ($mail->cards as $card) {
                $this->assertNotNull($card['price']);
                $this->assertGreaterThan(0, (float) $card['price']);
                $this->assertArrayNotHasKey('publisher_price', $card);
                $this->assertSame(40, $card['da']);
                $this->assertNotEmpty($card['metrics_checked_at']);
            }

            return true;
        });
    }

    public function test_multiple_pairs_do_not_use_a_combined_filter(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $techDe = $this->site($publisher, [
            'country' => 'de',
            'countries' => ['de'],
            'category' => 'Technology',
            'categories' => ['Technology'],
            'first_catalogued_at' => now()->subMonths(2),
        ]);
        $this->paidOrderFor($advertiser, $techDe);
        $this->newMatch($publisher, ['domain' => 'm-us-1.example']);
        $this->newMatch($publisher, [
            'country' => 'de',
            'countries' => ['de'],
            'category' => 'Technology',
            'categories' => ['Technology'],
            'domain' => 't-de-1.example',
        ]);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();

        Mail::assertQueued(WeeklyNewSitesDigestMail::class, function (WeeklyNewSitesDigestMail $mail) {
            $this->assertGreaterThanOrEqual(2, count($mail->browseLinks));
            $urls = collect($mail->browseLinks)->pluck('url')->implode(' ');
            $this->assertStringNotContainsString('country=us,de', $urls);
            $this->assertStringNotContainsString('country=de,us', $urls);

            return true;
        });
    }

    public function test_signed_links_hide_identifiers_and_block_open_redirect(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $url = WeeklyNewSitesLink::mint($advertiser, WeeklyNewSitesToken::PURPOSE_UNSUB);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        $this->assertMatchesRegularExpression('#^/email/weekly-new-sites/[A-Za-z0-9]+$#', $path);
        $this->assertStringNotContainsString($advertiser->email, $url);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|&)(?:user_id|user|uid|advertiser_id)='.preg_quote((string) $advertiser->id, '/').'(?:&|$)/',
            $query
        );

        $relative = $this->relativeSigned($url);
        $this->get($relative)->assertOk()->assertSee('Unsubscribe from the new-site digest', false);
        $this->post($relative)->assertOk();
        $this->assertTrue(app(PermissionGate::class)->explicitlyDisabled($advertiser->fresh(), 'weekly_new_sites'));

        $evil = WeeklyNewSitesLink::mint($advertiser, WeeklyNewSitesToken::PURPOSE_CLICK, [
            'kind' => 'site',
            'path' => 'https://evil.example/phish',
            'site_id' => 1,
        ]);
        $this->get($this->relativeSigned($evil))->assertRedirect('/advertiser/catalog');
    }

    public function test_preferences_can_add_and_disable_pairs(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->actingAs($advertiser)
            ->get(route('advertiser.weekly-new-sites.edit'))
            ->assertOk()
            ->assertSee('Marketing');

        $pair = WeeklyNewSitesInterest::query()->where('user_id', $advertiser->id)->first();
        $this->actingAs($advertiser)
            ->post(route('advertiser.weekly-new-sites.update'), [
                'digest_enabled' => '1',
                'enabled_ids' => [],
                'add_niche' => 'Travel',
                'add_country' => 'fr',
            ])
            ->assertRedirect();

        $this->assertFalse($pair->fresh()->enabled);
        $this->assertTrue(
            WeeklyNewSitesInterest::query()
                ->where('user_id', $advertiser->id)
                ->where('niche', 'Travel')
                ->where('country_code', 'fr')
                ->where('enabled', true)
                ->exists()
        );
    }

    public function test_admin_page_dry_run_does_not_send(): void
    {
        $admin = $this->userWithRole('admin');
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->actingAs($admin)
            ->get(route('admin.weekly-new-sites.index'))
            ->assertOk()
            ->assertSee('Weekly new-site digest')
            ->assertSee('Observational only');

        $this->actingAs($admin)
            ->post(route('admin.weekly-new-sites.dry-run'))
            ->assertRedirect();

        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
        $this->assertSame(0, WeeklyNewSitesRecipient::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_dry_run_command_sends_no_mail(): void
    {
        [$publisher] = $this->eligibleAdvertiser();
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest', ['--dry-run' => true])->assertSuccessful();
        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
    }

    public function test_click_report_does_not_invent_attribution(): void
    {
        $src = strtolower((string) file_get_contents(app_path('Services/WeeklyNewSites/AttributionReport.php')));
        $this->assertStringContainsString('not claimed as caused', $src);
        $this->assertStringContainsString('observational', strtolower((string) file_get_contents(base_path('docs/weekly-new-sites-digest.md'))));
    }

    public function test_permanent_bounce_blocks(): void
    {
        [$publisher, $advertiser] = $this->eligibleAdvertiser();
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WeeklyNewSitesDigestMail::class,
            'to_email' => $advertiser->email,
            'subject' => 'x',
            'status' => EmailLog::STATUS_FAILED,
            'error' => '550 user unknown',
        ]);
        $this->newMatch($publisher);
        $this->newMatch($publisher);

        $this->artisan('emails:send-weekly-new-sites-digest')->assertSuccessful();
        Mail::assertNotQueued(WeeklyNewSitesDigestMail::class);
    }

    private function relativeSigned(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
