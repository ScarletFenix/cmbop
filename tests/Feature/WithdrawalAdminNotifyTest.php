<?php

namespace Tests\Feature;

use App\Mail\WithdrawalRequestNotification;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\EmailNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WithdrawalAdminNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function userWithRoles(array $roleNames, ?string $activeRole = null): User
    {
        $roles = Role::query()->whereIn('name', $roleNames)->get();
        $this->assertNotEmpty($roles);

        $active = $roles->firstWhere('name', $activeRole ?: $roleNames[0]) ?: $roles->first();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $active->id,
        ]);
        $user->roles()->attach($roles->pluck('id')->all());

        return $user->fresh();
    }

    private function publisherWithBalance(float $balance = 100): User
    {
        $publisher = $this->userWithRoles(['publisher']);
        $roleId = (int) $publisher->active_role_id;

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $publisher;
    }

    public function test_publisher_withdraw_notifies_role_pivot_admin_and_links_to_queue(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $marketingRole = Role::where('name', 'marketing')->firstOrFail();

        // Admin on pivot, but active role is marketing — old active_role_id lookup missed these.
        $admin = User::factory()->create([
            'email' => 'ops-withdraw@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $admin->roles()->attach([$adminRole->id, $marketingRole->id]);

        $publisher = $this->publisherWithBalance();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $withdrawal = Withdrawal::query()->where('user_id', $publisher->id)->latest('id')->first();
        $this->assertNotNull($withdrawal);

        Mail::assertQueued(WithdrawalRequestNotification::class, function (WithdrawalRequestNotification $mail) use ($admin, $withdrawal) {
            return $mail->hasTo($admin->email)
                && (int) $mail->withdrawal->id === (int) $withdrawal->id;
        });

        $mailable = new WithdrawalRequestNotification($withdrawal, $publisher);
        $html = $mailable->render();
        $this->assertStringContainsString('/admin/withdrawals/'.$withdrawal->id.'/mark-paid-confirm', $html);
        $this->assertStringContainsString('signature=', $html);
        $this->assertStringContainsString('Mark paid (confirm)', $html);
        $this->assertStringContainsString('/admin/withdrawals', $html);
        $this->assertStringNotContainsString("url => '#'", $html);
        $this->assertStringNotContainsString('href="#"', $html);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('audience', InAppNotification::AUDIENCE_ADMIN)
            ->where('related_type', Withdrawal::class)
            ->where('related_id', $withdrawal->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertStringContainsString('/admin/withdrawals', (string) $note->action_url);
    }

    public function test_withdrawal_bell_still_fires_when_mail_dispatch_fails(): void
    {
        $admin = $this->userWithRoles(['admin']);
        $publisher = $this->publisherWithBalance();

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);

        // Force mail path to throw for every admin dispatch by clearing admin emails.
        $admin->email = '';
        $admin->save();
        config(['mail.admin_email' => null, 'email_notifications.brand.support_email' => null]);

        app(EmailNotificationService::class)->notifyAdminsWithdrawalRequested($withdrawal, $publisher);

        $note = InAppNotification::query()
            ->where('user_id', $admin->id)
            ->where('related_type', Withdrawal::class)
            ->where('related_id', $withdrawal->id)
            ->first();

        $this->assertNotNull($note, 'Bell must still fire when no admin mailbox is available');
        Mail::assertNothingQueued();
    }
}
