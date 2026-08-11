<?php

namespace Tests\Feature;

use App\Mail\WithdrawalRequestNotification;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalEmailMarkPaidCtaWiringTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    public function test_withdrawal_request_mail_primary_cta_is_signed_mark_paid_confirm(): void
    {
        $publisher = $this->publisher();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 120,
            'fee' => 0,
            'net_amount' => 120,
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Test Bank',
                'account_holder' => 'Pub',
                'account_number' => 'BE00',
            ],
            'status' => 'pending',
        ]);

        $mail = new WithdrawalRequestNotification($withdrawal, $publisher);
        $built = $mail->build();

        $this->assertSame('withdrawal_request', $mail->notificationType);
        $this->assertStringContainsString(
            '/admin/withdrawals/'.$withdrawal->id.'/mark-paid-confirm',
            (string) $built->viewData['markPaidUrl']
        );
        $this->assertStringContainsString('signature=', (string) $built->viewData['markPaidUrl']);

        $html = $mail->render();
        $this->assertStringContainsString('Mark paid (confirm)', $html);
        $this->assertStringContainsString('payout queue', strtolower(strip_tags($html)));
    }

    public function test_email_catalog_copy_mentions_mark_paid_confirm(): void
    {
        $catalog = EmailCatalog::all();
        $this->assertStringContainsString(
            'mark-paid',
            strtolower((string) ($catalog['withdrawal_request']['description'] ?? ''))
        );

        $preview = EmailCatalog::makeMailable('withdrawal_request');
        $this->assertInstanceOf(WithdrawalRequestNotification::class, $preview);
        $html = $preview->render();
        $this->assertStringContainsString('mark-paid-confirm', $html);
        $this->assertStringContainsString('signature=', $html);
    }
}
