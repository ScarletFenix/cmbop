<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\DepositRequest;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminEmailCenterTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function seedLiveCustomerRecords(): User
    {
        $leaked = $this->userWithRole('advertiser', [
            'name' => 'Leaked Customer',
            'email' => 'leaked@example.com',
        ]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Leaked Publisher',
            'email' => 'leaked-publisher@example.com',
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leaked Publisher Site',
            'site_url' => 'https://leaked-site.example',
            'domain' => 'leaked-site.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'Live row that previews must not use',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $leaked->id,
            'order_number' => 'ORD-LEAKED',
            'reference_code' => 'REF-LEAKED',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 100,
            'publisher_price' => 85,
            'content_link' => 'https://leaked-site.example/article.docx',
        ]);

        DepositRequest::create([
            'user_id' => $leaked->id,
            'reference_code' => 'DEP-LEAKED',
            'amount' => 250,
            'payment_method' => 'bank_transfer',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 80,
            'fee' => 0,
            'net_amount' => 80,
            'payment_method' => 'paypal',
            'status' => 'pending',
        ]);

        Invoice::create([
            'user_id' => $leaked->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-LEAKED-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $leaked->name,
            'customer_email' => $leaked->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => $order->order_number,
            'line_items' => [],
            'billing_snapshot' => [],
        ]);

        ProblemReport::create([
            'user_id' => $leaked->id,
            'name' => $leaked->name,
            'email' => $leaked->email,
            'subject' => 'Leaked problem',
            'message' => 'Should never appear in Email Center previews.',
            'status' => 'resolved',
            'admin_notes' => 'Leaked admin notes',
        ]);

        WebsiteSuggestion::create([
            'user_id' => $leaked->id,
            'website_name' => 'Leaked Tech Blog',
            'website_url' => 'https://leaked-tech.example',
            'domain' => 'leaked-tech.example',
            'status' => 'accepted',
            'admin_notes' => 'Leaked suggestion notes',
        ]);

        SiteClaim::create([
            'site_id' => $site->id,
            'claimer_id' => $leaked->id,
            'website_name' => $site->site_name,
            'website_url' => $site->site_url,
            'domain' => $site->domain,
            'name_matches' => true,
            'proof_message' => 'Leaked ownership proof',
            'contact_email' => $leaked->email,
            'status' => 'pending',
        ]);

        return $leaked;
    }

    public function test_guest_is_redirected_from_email_center(): void
    {
        $this->get(route('admin.emails.index'))->assertRedirect(route('login'));
        $this->get(route('admin.emails.preview', 'welcome'))->assertRedirect(route('login'));
    }

    public function test_advertiser_and_publisher_cannot_open_email_center(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->actingAs($advertiser)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($publisher)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($advertiser)->get(route('admin.emails.preview', 'welcome'))->assertForbidden();
    }

    public function test_admin_can_open_email_center(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Email Center', false)
            ->assertSee('synthetic preview', false);
    }

    public function test_admin_can_preview_welcome_and_unknown_key_is_404(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'welcome'))
            ->assertOk()
            ->assertSee('Welcome aboard, Sample', false);

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'not-a-real-template'))
            ->assertNotFound();
    }

    public function test_catalog_previews_stay_synthetic_when_live_rows_exist(): void
    {
        $this->seedLiveCustomerRecords();
        $admin = $this->userWithRole('admin');
        $invoicesBefore = Invoice::query()->count();

        foreach (array_keys(EmailCatalog::all()) as $key) {
            $html = $this->actingAs($admin)
                ->get(route('admin.emails.preview', $key))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString('leaked@example.com', $html, $key);
            $this->assertStringNotContainsString('Leaked Customer', $html, $key);
            $this->assertStringNotContainsString('leaked-publisher@example.com', $html, $key);
            $this->assertStringNotContainsString('ORD-LEAKED', $html, $key);
            $this->assertStringNotContainsString('DEP-LEAKED', $html, $key);
            $this->assertStringNotContainsString('INV-LEAKED-1', $html, $key);
        }

        $this->assertSame($invoicesBefore, Invoice::query()->count());
    }

    public function test_welcome_preview_uses_placeholder_verify_url(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'welcome'))
            ->assertOk()
            ->assertSee('/email/verify/preview-id/preview-hash', false);
    }

    public function test_send_test_rejects_non_admin_inbox(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => 'someone-else@example.com',
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_send_test_delivers_welcome_and_logs_once(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $log = EmailLog::query()->first();
        $this->assertSame($admin->email, $log->to_email);
        $this->assertSame('welcome', $log->template_key);
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->status);
        $this->assertSame('email_center_test', $log->meta['source'] ?? null);
        $this->assertStringNotContainsString('leaked@example.com', (string) $log->subject);
    }

    public function test_send_test_bypasses_global_disable_and_dedupe(): void
    {
        $admin = $this->userWithRole('admin');
        EmailNotificationSetting::updateOrCreate(
            ['type' => 'welcome'],
            ['enabled' => false]
        );
        EmailNotificationSetting::flushCache('welcome');
        $this->assertFalse(EmailNotificationSetting::isEnabled('welcome'));

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $this->assertSame(2, EmailLog::query()->where('template_key', 'welcome')->count());
    }

    public function test_send_test_records_mailable_when_faked(): void
    {
        Mail::fake();
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'welcome',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->forceSend === true
                && EmailCatalog::isPreviewUser($mail->user);
        });
    }

    public function test_send_test_every_template_succeeds_without_live_side_effects(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedLiveCustomerRecords();
        $admin = $this->userWithRole('admin');
        $invoicesBefore = Invoice::query()->count();

        foreach (array_keys(EmailCatalog::templates()) as $key) {
            $response = $this->actingAs($admin)
                ->from(route('admin.emails.index'))
                ->post(route('admin.emails.test'), [
                    'template' => $key,
                    'email' => $admin->email,
                ]);

            $this->assertTrue(
                $response->isRedirect(route('admin.emails.index')),
                $key.' status '.$response->status().': '.($response->exception?->getMessage() ?? '')
            );
            $this->assertTrue(
                $response->getSession()->has('success'),
                $key.': '.($response->getSession()->get('error') ?? $response->exception?->getMessage() ?? 'missing success flash')
            );
        }

        $this->assertSame($invoicesBefore, Invoice::query()->count());
        $this->assertSame(
            count(EmailCatalog::templates()),
            EmailLog::query()->where('to_email', $admin->email)->where('status', EmailLog::STATUS_DELIVERED)->count()
        );
    }

    public function test_send_test_password_reset_does_not_invent_a_delivered_row_on_failure_path(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'password_reset',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $logs = EmailLog::query()->where('to_email', $admin->email)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('password_reset', $logs->first()->template_key);
        $this->assertSame(EmailLog::STATUS_DELIVERED, $logs->first()->status);
        $this->assertSame('email_center_test', $logs->first()->meta['source'] ?? null);
        $this->assertNotEmpty($logs->first()->dedupe_key);
    }

    public function test_catalog_keys_match_notification_config(): void
    {
        $configKeys = array_keys(config('email_notifications.types'));

        $this->assertEqualsCanonicalizing($configKeys, array_keys(EmailCatalog::all()));
        $this->assertSame($configKeys, array_keys(EmailCatalog::templates()));
        $this->assertArrayHasKey('spend_budget_alert', EmailCatalog::templates());
        foreach ([
            'email_verification',
            'content_evaluation_result',
            'site_discount_ended',
            'payout_profile_updated',
            'bulk_site_request_submitted',
            'bulk_sites_seeded',
            'admin_assigned_site',
            'audience_campaign',
            'bulk_request_cancelled',
            'bulk_request_items_rejected',
            'spend_budget_alert',
        ] as $key) {
            $this->assertArrayHasKey($key, EmailCatalog::templates());
            $this->assertNotSame('', EmailCatalog::templates()[$key]['description'] ?? '');
            $this->assertNotSame('Other', EmailCatalog::templates()[$key]['category'] ?? 'Other');
        }
    }

    public function test_email_center_lists_templates_from_config_including_spend_budget_alert(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Spend Budget Alert', false)
            ->assertSee('Email Verification', false)
            ->assertSee('Content Evaluation Result', false)
            ->assertSee('Site Discount Ended', false)
            ->assertSee('Payout Profile Updated by Support', false)
            ->assertSee('Bulk Site Request Submitted', false)
            ->assertSee('Bulk Sites Seeded', false)
            ->assertSee('Admin Assigned Site', false)
            ->assertSee('Updates & Campaigns')
            ->assertSee('Bulk Website Request Cancelled', false)
            ->assertSee('Bulk Request Sites Not Added', false);
    }

    public function test_key_from_subject_uses_unique_needles_longest_first(): void
    {
        $cases = [
            'Welcome to SEOLinkBuildings' => 'welcome',
            'New Withdrawal Request - €50.00' => 'withdrawal_request',
            'Withdrawal request received (WD-12)' => 'withdrawal_requested_confirmation',
            'Withdrawal Request Approved' => 'withdrawal_status',
            'Withdrawal Request Completed' => 'withdrawal_status',
            'New Order for Your Site: Example' => 'publisher_new_order',
            'New order #ORD-1 created' => 'order_status_changed',
            'Manual Payment Required - New Order #ORD-1' => 'admin_manual_payment',
            'Order Accepted - #ORD-1' => 'order_accepted',
            'Payment Confirmed for Order #ORD-1' => 'order_payment_confirmed',
            'Payment Successful – Invoice Attached' => 'payment_successful_invoice',
            'Deposit Approved - €100.00' => 'deposit_approved',
            'New Deposit Request - €100.00' => 'deposit_submitted',
            'Your site discount has ended — Sample Site' => 'site_discount_ended',
            'Your payout details were updated' => 'payout_profile_updated',
            'Your article was approved for publication' => 'content_evaluation_result',
            'Article evaluation update: action needed' => 'content_evaluation_result',
            'Bulk site request from Sample User' => 'bulk_site_request_submitted',
            'Your sites were added to Pending sites' => 'bulk_sites_seeded',
            'Please accept a website we added for you' => 'admin_assigned_site',
            'Your bulk website request was cancelled' => 'bulk_request_cancelled',
            'We did not add a site from bulk request #0' => 'bulk_request_items_rejected',
            'Spend budget warning' => 'spend_budget_alert',
            'Monthly spend budget reached' => 'spend_budget_alert',
            'Low wallet balance alert' => 'spend_budget_alert',
            'Verify your email (Test Preview)' => 'email_verification',
            'Password Reset (Test Preview)' => 'password_reset',
        ];

        foreach ($cases as $subject => $expected) {
            $this->assertSame($expected, EmailCatalog::keyFromSubject($subject), $subject);
        }
    }

    public function test_every_notification_type_has_a_preview(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (array_keys(config('email_notifications.types')) as $key) {
            $this->actingAs($admin)
                ->get(route('admin.emails.preview', $key))
                ->assertOk();
        }
    }

    public function test_email_verification_preview_is_a_placeholder(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.preview', 'email_verification'))
            ->assertOk()
            ->assertSee('/email/verify/preview-id/preview-hash', false);
    }

    public function test_settings_reject_empty_payload_and_skip_framework_types(): void
    {
        $admin = $this->userWithRole('admin');
        EmailNotificationSetting::updateOrCreate(
            ['type' => 'password_reset'],
            ['enabled' => true]
        );

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.settings'), [])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHasErrors('enabled');

        $this->assertTrue(EmailNotificationSetting::query()->where('type', 'password_reset')->value('enabled'));

        $enabled = [];
        foreach (config('email_notifications.types') as $type => $meta) {
            if (! empty($meta['framework'])) {
                continue;
            }
            $enabled[$type] = $type === 'welcome' ? '0' : '1';
        }

        $this->actingAs($admin)
            ->post(route('admin.emails.settings'), ['enabled' => $enabled])
            ->assertSessionHas('success');

        EmailNotificationSetting::flushCache();
        $this->assertFalse(EmailNotificationSetting::isEnabled('welcome'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('password_reset'));
        $this->assertTrue(EmailNotificationSetting::isEnabled('email_verification'));
    }

    public function test_retry_only_retries_mail_failed_jobs_and_leaves_logs(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $otherUuid = (string) Str::uuid();

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'subject' => 'Welcome',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => $mailUuid,
                'connection' => 'database',
                'queue' => 'emails',
                'payload' => json_encode([
                    'displayName' => 'App\\Mail\\WelcomeEmail',
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
                ]),
                'exception' => 'SMTP failed',
                'failed_at' => now(),
            ],
            [
                'uuid' => $otherUuid,
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\EnrichSiteJob',
                    'data' => ['commandName' => 'App\\Jobs\\EnrichSiteJob'],
                ]),
                'exception' => 'timeout',
                'failed_at' => now(),
            ],
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$mailUuid]])
            ->andReturn(0);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'))
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(EmailLog::STATUS_FAILED, EmailLog::query()->first()->status);
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $otherUuid)->exists());
    }

    public function test_kpis_do_not_double_count_queue_jobs(): void
    {
        $admin = $this->userWithRole('admin');

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now()->subDay(),
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_PENDING,
        ]);
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'bounce',
        ]);

        DB::table('jobs')->insert([
            'queue' => 'emails',
            'payload' => json_encode(['displayName' => 'App\\Mail\\WelcomeEmail']),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Delivered Today', $html);
        preg_match_all('/<div class="value[^"]*">\s*([0-9,]+)\s*<\/div>/', $html, $matches);
        $values = array_map(fn ($v) => (int) str_replace(',', '', $v), $matches[1] ?? []);
        $this->assertSame([1, 1, 1, 1], $values);
    }

    public function test_mailable_failed_hook_writes_email_log(): void
    {
        $mail = EmailCatalog::makeMailable('welcome');
        $this->assertNotNull($mail);
        $mail->failed(new \RuntimeException('SMTP down'));

        $this->assertDatabaseHas('email_logs', [
            'template_key' => 'welcome',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
        ]);
    }

    public function test_successful_send_updates_failed_log_with_same_dedupe_key(): void
    {
        $admin = $this->userWithRole('admin');
        $mail = EmailCatalog::makeMailable('welcome');
        $this->assertNotNull($mail);
        $mail->forceSend = true;
        $mail->skipUserPreference = true;
        $mail->dedupeKey = 'welcome-dedupe-retry';
        $mail->failed(new \RuntimeException('SMTP down'));

        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(EmailLog::STATUS_FAILED, EmailLog::query()->value('status'));

        Mail::to($admin->email)->sendNow($mail);

        $this->assertSame(1, EmailLog::query()->count());
        $log = EmailLog::query()->first();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->status);
        $this->assertSame($admin->email, $log->to_email);
        $this->assertNull($log->error);
        $this->assertSame(2, $log->attempts);
    }

    public function test_retry_rebuilds_framework_test_log_without_duplicate(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.emails.test'), [
                'template' => 'password_reset',
                'email' => $admin->email,
            ])
            ->assertSessionHas('success');

        $log = EmailLog::query()->first();
        $this->assertNotNull($log);
        $log->update([
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertSame('email_center_test', $fresh->meta['source'] ?? null);
    }

    public function test_retry_rebuilds_legacy_framework_log_without_source(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'email_verification',
            'to_email' => $admin->email,
            'subject' => 'Verify your email (Test Preview)',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $this->assertSame(EmailLog::STATUS_DELIVERED, $log->fresh()->status);
    }

    public function test_retry_rebuilds_email_center_test_log(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'dedupe_key' => 'email_center_test:welcome:fixed',
            'to_email' => $admin->email,
            'subject' => 'Welcome (Test)',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'email_center_test'],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, EmailLog::query()->count());
        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $fresh->status);
        $this->assertSame($admin->email, $fresh->to_email);
        $this->assertNull($fresh->error);
    }

    public function test_retry_production_log_without_job_does_not_rebuild(): void
    {
        $admin = $this->userWithRole('admin');
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => WelcomeEmail::class,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('error');

        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->assertSame(0, EmailLog::query()->where('status', EmailLog::STATUS_DELIVERED)->count());
    }

    public function test_retry_production_log_requeues_matching_mail_job(): void
    {
        $admin = $this->userWithRole('admin');
        $mailUuid = (string) Str::uuid();
        $log = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => null,
            'template_key' => 'welcome',
            'to_email' => 'customer@example.com',
            'subject' => 'Welcome to SEOLinkBuildings',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['source' => 'queue'],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => $mailUuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => WelcomeEmail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP failed',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$mailUuid]])
            ->andReturn(0);

        $this->actingAs($admin)
            ->from(route('admin.emails.index'))
            ->post(route('admin.emails.retry'), ['log_id' => $log->id])
            ->assertRedirect(route('admin.emails.index'))
            ->assertSessionHas('success');

        $fresh = $log->fresh();
        $this->assertSame(EmailLog::STATUS_PENDING, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
        $this->assertNull($fresh->error);
    }

    public function test_email_center_index_stays_under_query_budget(): void
    {
        $admin = $this->userWithRole('admin');
        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'template_key' => 'welcome',
            'to_email' => $admin->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'sent_at' => now(),
        ]);

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.emails.index'))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $emailLogQueries = collect($log)->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'email_logs')
        )->count();

        $this->assertLessThan(8, $emailLogQueries, 'email_logs queried '.$emailLogQueries.' times');
        $this->assertLessThan(80, count($log), 'Email Center index ran '.count($log).' queries');
    }

    public function test_retry_confirm_copy_does_not_claim_to_reset_logs(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->assertSee('Retry failed mail jobs', false)
            ->assertDontSee('reset failed email logs', false);
    }
}
