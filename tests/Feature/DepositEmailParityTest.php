<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletStripeDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1 — deposit email parity: Stripe card credits and admin bank/Wise
 * approvals both send DepositApproved with an RCT receipt PDF attached.
 */
class DepositEmailParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Ada Advertiser',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function walletFor(User $user): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_stripe_card_credit_queues_deposit_approved_email_once(): void
    {
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);
        $pi = 'pi_parity_'.uniqid();

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntent(
            $advertiser->id,
            $pi,
            75.50,
            'CARD75'
        );

        $this->assertSame(75.5, $credited);

        Mail::assertQueued(DepositApproved::class, function (DepositApproved $mail) use ($advertiser) {
            return (int) $mail->deposit->user_id === (int) $advertiser->id
                && (float) $mail->deposit->amount === 75.5
                && $mail->notificationType === 'deposit_approved'
                && $mail->dedupeKey === 'deposit_approved:'.$mail->deposit->id;
        });

        // Idempotent re-credit must not queue another mail.
        app(WalletStripeDepositService::class)->creditFromPaymentIntent(
            $advertiser->id,
            $pi,
            75.50,
            'CARD75DUP'
        );

        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_stripe_checkout_session_queues_deposit_approved_email(): void
    {
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);
        $sessionId = 'cs_parity_'.uniqid();

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_session_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
                'reference_code' => 'DEP-CS-40',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_admin_bank_approve_queues_deposit_approved_email(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-90',
            'amount' => 90,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', true);

        Mail::assertQueued(DepositApproved::class, function (DepositApproved $mail) use ($deposit) {
            return (int) $mail->deposit->id === (int) $deposit->id
                && $mail->notificationType === 'deposit_approved';
        });
    }

    public function test_deposit_approved_mail_attaches_receipt_pdf(): void
    {
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-PDF-55',
            'amount' => 55,
            'payment_method' => 'card',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $mailable = new DepositApproved($deposit->fresh(['user']));
        $built = $mailable->build();

        $receipt = Invoice::query()
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('reference_code', $deposit->reference_code)
            ->first();

        $this->assertNotNull($receipt);
        $this->assertNotNull($receipt->pdf_path);
        Storage::disk('local')->assertExists($receipt->pdf_path);

        $attachments = $built->attachments ?? [];
        $this->assertNotEmpty($attachments, 'Expected a receipt PDF attachment on DepositApproved.');

        $matched = collect($attachments)->contains(function ($attachment) use ($receipt) {
            if (is_array($attachment)) {
                $name = $attachment['options']['as']
                    ?? $attachment['as']
                    ?? $attachment['name']
                    ?? '';
                $file = $attachment['file'] ?? $attachment['path'] ?? '';

                return str_contains((string) $name, $receipt->invoice_number)
                    || str_contains((string) $file, (string) $receipt->pdf_path);
            }

            $name = $attachment->as ?? $attachment->name ?? '';
            $file = $attachment->path ?? $attachment->file ?? '';

            return str_contains((string) $name, $receipt->invoice_number)
                || str_contains((string) $file, (string) $receipt->pdf_path);
        });

        $this->assertTrue($matched, 'Expected receipt PDF attachment for '.$receipt->invoice_number);

        $html = $mailable->render();
        $this->assertStringContainsString('Wallet topped up', $html);
        $this->assertStringContainsString($receipt->invoice_number, $html);
        $this->assertStringContainsString('receipt PDF is attached', $html);
    }

    public function test_bank_deposit_approved_mail_copy_differs_from_card(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-COPY',
            'amount' => 30,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $mailable = new DepositApproved($deposit->fresh(['user']));
        $built = $mailable->build();

        $this->assertStringContainsString('Deposit Approved', $built->subject);
        $html = $mailable->render();
        $this->assertStringContainsString('Deposit Approved', $html);
        $this->assertStringContainsString('approved', strtolower(strip_tags($html)));
        $this->assertStringNotContainsString('card payment succeeded', $html);
        $this->assertStringNotContainsString('PayPal payment succeeded', $html);
        $this->assertStringNotContainsString('Wallet topped up', $html);
    }

    public function test_paypal_deposit_approved_mail_uses_instant_top_up_copy(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-PP-COPY',
            'amount' => 40,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $mailable = new DepositApproved($deposit->fresh(['user']));
        $built = $mailable->build();

        $this->assertStringContainsString('Wallet topped up', $built->subject);
        $this->assertStringNotContainsString('Deposit Approved', $built->subject);
        $html = $mailable->render();
        $this->assertStringContainsString('Wallet topped up', $html);
        $this->assertStringContainsString('PayPal payment succeeded', $html);
        $this->assertStringContainsString('PayPal', $html);
        $this->assertStringNotContainsString('Paypal', $html);
        $this->assertStringNotContainsString('request has been', $html);
    }

    public function test_deposit_email_shows_advertiser_wallet_not_active_role_wallet(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->syncWithoutDetaching([$advertiserRole->id, $publisherRole->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 125.50,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 3.00,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-ROLE-BAL',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $html = (new DepositApproved($deposit->fresh(['user'])))->render();

        $this->assertStringContainsString('€125.50', $html);
        $this->assertStringNotContainsString('€3.00', $html);
    }
}
