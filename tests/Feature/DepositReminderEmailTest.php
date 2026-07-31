<?php

namespace Tests\Feature;

use App\Mail\DepositReminderMail;
use App\Models\DepositRequest;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DepositReminderEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeAdvertiser(array $overrides = []): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ], $overrides));
        $user->roles()->attach($role->id);

        $advRoleId = $role->id;
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advRoleId,
            'balance' => 20,
            'bonus_balance' => 20,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    private function deposit(User $user, string $status): void
    {
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => $status,
        ]);
    }

    public function test_day14_reminder_queues_for_never_deposited_advertiser(): void
    {
        Mail::fake();

        $target = $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(10, 30),
            'updated_at' => now()->subDays(14)->setTime(10, 30),
        ]);
        $funded = $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(11, 0),
            'updated_at' => now()->subDays(14)->setTime(11, 0),
        ]);
        $this->deposit($funded, 'completed');

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day14']);

        Mail::assertQueued(DepositReminderMail::class, function (DepositReminderMail $mail) use ($target) {
            return $mail->hasTo($target->email)
                && $mail->step === DepositReminderMail::STEP_DAY14
                && $mail->dedupeKey === 'deposit_reminder:day14:'.$target->id;
        });
        Mail::assertNotQueued(DepositReminderMail::class, function (DepositReminderMail $mail) use ($funded) {
            return $mail->hasTo($funded->email);
        });
    }

    public function test_day7_reminder_queues_for_eligible_advertiser(): void
    {
        Mail::fake();

        $target = $this->makeAdvertiser([
            'created_at' => now()->subDays(7)->setTime(9, 15),
            'updated_at' => now()->subDays(7)->setTime(9, 15),
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day7']);

        Mail::assertQueued(DepositReminderMail::class, function (DepositReminderMail $mail) use ($target) {
            return $mail->hasTo($target->email)
                && $mail->step === DepositReminderMail::STEP_DAY7;
        });
    }

    public function test_skips_unverified_and_wrong_age_and_publishers(): void
    {
        Mail::fake();

        $unverified = $this->makeAdvertiser([
            'email_verified_at' => null,
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ]);
        $wrongAge = $this->makeAdvertiser([
            'created_at' => now()->subDays(10)->setTime(12, 0),
            'updated_at' => now()->subDays(10)->setTime(12, 0),
        ]);
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ]);
        $publisher->roles()->attach($publisherRole->id);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day14']);

        Mail::assertNotQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($unverified->email));
        Mail::assertNotQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($wrongAge->email));
        Mail::assertNotQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($publisher->email));
    }

    public function test_skips_when_marketing_emails_disabled(): void
    {
        Mail::fake();

        $user = $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ]);
        EmailNotificationPreference::create([
            'user_id' => $user->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day14']);

        // Queued then suppressed on send — PlatformMailable may still queue.
        // Force sync send path by processing: assert no delivered log / or assertQueued then that policy blocks.
        // With Mail::fake, ShouldQueue mailables are recorded as queued even if policy would suppress on send.
        // Re-run without fake by asserting artisan dry-run eligibility still includes them,
        // and that a real sync send does not deliver.
        Mail::assertQueued(DepositReminderMail::class);

        // Simulate worker send: policy suppresses → no parent send. Use Mail::fake cleared and send sync.
        $mailable = new DepositReminderMail($user, DepositReminderMail::STEP_DAY14);
        $mailable->dedupeKey = 'deposit_reminder:day14:'.$user->id;
        $this->assertNull($mailable->send(app('mailer')));
    }

    public function test_welcome_bonus_alone_still_eligible(): void
    {
        Mail::fake();

        $user = $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(8, 0),
            'updated_at' => now()->subDays(14)->setTime(8, 0),
        ]);
        $this->assertSame(20.0, (float) $user->wallets()->first()->balance);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day14']);

        Mail::assertQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($user->email));
    }

    public function test_dedupe_prevents_second_delivery(): void
    {
        $user = $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ]);

        EmailLog::create([
            'notification_type' => 'deposit_reminder',
            'dedupe_key' => 'deposit_reminder:day14:'.$user->id,
            'to_email' => $user->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'mailable' => DepositReminderMail::class,
        ]);

        $mailable = new DepositReminderMail($user, DepositReminderMail::STEP_DAY14);
        $mailable->dedupeKey = 'deposit_reminder:day14:'.$user->id;
        $this->assertNull($mailable->send(app('mailer')));
    }

    public function test_dry_run_does_not_queue_mail(): void
    {
        Mail::fake();

        $this->makeAdvertiser([
            'created_at' => now()->subDays(14)->setTime(12, 0),
            'updated_at' => now()->subDays(14)->setTime(12, 0),
        ]);

        Artisan::call('emails:send-deposit-reminders', [
            '--step' => 'day14',
            '--dry-run' => true,
        ]);

        Mail::assertNothingQueued();
    }

    public function test_mailable_renders_day7_and_day14_copy(): void
    {
        $user = $this->makeAdvertiser();

        $day7 = (new DepositReminderMail($user, DepositReminderMail::STEP_DAY7))->render();
        $this->assertStringContainsString('€20 credit is waiting', $day7);
        $this->assertStringContainsString('Browse catalog', $day7);

        $day14 = (new DepositReminderMail($user, DepositReminderMail::STEP_DAY14))->render();
        $this->assertStringContainsString('Add funds to place your first guest post', $day14);
        $this->assertStringContainsString('Add funds now', $day14);
        $this->assertStringContainsString('/advertiser/add-funds', $day14);
    }
}
