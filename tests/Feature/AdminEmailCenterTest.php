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
    }

    public function test_catalog_keys_match_notification_config(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(config('email_notifications.types')),
            array_keys(EmailCatalog::all())
        );
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
}
