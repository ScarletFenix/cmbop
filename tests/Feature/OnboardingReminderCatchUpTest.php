<?php

namespace Tests\Feature;

use App\Mail\DepositReminderMail;
use App\Mail\PublisherAddSiteReminderMail;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 2.2 — onboarding reminders use age windows + sent columns so a missed
 * cron day still catches up without re-sending a completed step.
 */
class OnboardingReminderCatchUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function advertiser(array $overrides = []): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => 20,
            'bonus_balance' => 20,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    private function publisher(array $overrides = []): User
    {
        $role = Role::where('name', 'publisher')->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_deposit_day7_catch_up_for_nine_day_old_advertiser(): void
    {
        $target = $this->advertiser([
            'created_at' => now()->subDays(9)->setTime(12, 0),
            'updated_at' => now()->subDays(9)->setTime(12, 0),
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day7']);

        Mail::assertQueued(DepositReminderMail::class, function (DepositReminderMail $mail) use ($target) {
            return $mail->hasTo($target->email) && $mail->step === DepositReminderMail::STEP_DAY7;
        });
        $this->assertNotNull($target->fresh()->deposit_reminder_day7_sent_at);
    }

    public function test_deposit_day7_not_resent_after_column_marked(): void
    {
        $target = $this->advertiser([
            'created_at' => now()->subDays(9)->setTime(12, 0),
            'updated_at' => now()->subDays(9)->setTime(12, 0),
            'deposit_reminder_day7_sent_at' => now()->subDay(),
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day7']);

        Mail::assertNotQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($target->email));
    }

    public function test_deposit_day14_independent_of_missed_day7(): void
    {
        $target = $this->advertiser([
            'created_at' => now()->subDays(16)->setTime(12, 0),
            'updated_at' => now()->subDays(16)->setTime(12, 0),
            // Missed day7 entirely — day14 must still fire.
            'deposit_reminder_day7_sent_at' => null,
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day14']);

        Mail::assertQueued(DepositReminderMail::class, function (DepositReminderMail $mail) use ($target) {
            return $mail->hasTo($target->email) && $mail->step === DepositReminderMail::STEP_DAY14;
        });
        $this->assertNotNull($target->fresh()->deposit_reminder_day14_sent_at);
    }

    public function test_deposit_day7_window_excludes_day14_age(): void
    {
        $tooOld = $this->advertiser([
            'created_at' => now()->subDays(16)->setTime(12, 0),
            'updated_at' => now()->subDays(16)->setTime(12, 0),
        ]);

        Artisan::call('emails:send-deposit-reminders', ['--step' => 'day7']);

        Mail::assertNotQueued(DepositReminderMail::class, fn (DepositReminderMail $m) => $m->hasTo($tooOld->email));
    }

    public function test_publisher_day3_catch_up_for_five_day_old_publisher(): void
    {
        $target = $this->publisher([
            'created_at' => now()->subDays(5)->setTime(12, 0),
            'updated_at' => now()->subDays(5)->setTime(12, 0),
        ]);

        Artisan::call('emails:send-publisher-add-site-reminders', ['--step' => 'day3']);

        Mail::assertQueued(PublisherAddSiteReminderMail::class, function (PublisherAddSiteReminderMail $mail) use ($target) {
            return $mail->hasTo($target->email) && $mail->step === PublisherAddSiteReminderMail::STEP_DAY3;
        });
        $this->assertNotNull($target->fresh()->add_site_reminder_day3_sent_at);
    }

    public function test_publisher_day3_not_resent_after_column_marked(): void
    {
        $target = $this->publisher([
            'created_at' => now()->subDays(5)->setTime(12, 0),
            'updated_at' => now()->subDays(5)->setTime(12, 0),
            'add_site_reminder_day3_sent_at' => now()->subHours(6),
        ]);

        Artisan::call('emails:send-publisher-add-site-reminders', ['--step' => 'day3']);

        Mail::assertNotQueued(PublisherAddSiteReminderMail::class, fn (PublisherAddSiteReminderMail $m) => $m->hasTo($target->email));
    }
}
