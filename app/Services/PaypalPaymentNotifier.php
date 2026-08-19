<?php

namespace App\Services;

use App\Mail\PaypalPaymentNotCompleted;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaypalPaymentNotifier
{
    public function notifyNotCompleted(
        User $user,
        string $kind,
        string $referenceCode,
        string $reason
    ): void {
        $referenceCode = trim($referenceCode);
        if ($referenceCode === '' || ! $user->email) {
            return;
        }

        if ($this->alreadySettled($user, $kind, $referenceCode)) {
            return;
        }

        $gate = 'paypal_not_completed:'.$user->id.':'.$kind.':'.$referenceCode.':'.$reason;
        try {
            if (! Cache::add($gate, 1, now()->addMinutes(30))) {
                return;
            }
        } catch (\Throwable) {
            // Cache down — still send; PlatformMailable dedupe holds the worker.
        }

        try {
            Mail::to($user->email)->send(new PaypalPaymentNotCompleted(
                $user,
                $kind,
                $referenceCode,
                $reason
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send PayPal not-completed email: '.$e->getMessage(), [
                'user_id' => $user->id,
                'kind' => $kind,
                'reason' => $reason,
            ]);
        }

        try {
            app(InAppNotificationService::class)->notifyPaypalPaymentNotCompleted(
                $user,
                $kind,
                $referenceCode,
                $reason
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send PayPal not-completed bell: '.$e->getMessage(), [
                'user_id' => $user->id,
                'kind' => $kind,
            ]);
        }
    }

    public function notifyFromWebhookCustom(array $custom, string $reason): void
    {
        $userId = (int) ($custom['user_id'] ?? 0);
        $reference = trim((string) ($custom['reference_code'] ?? ''));
        $type = (string) ($custom['type'] ?? '');
        if ($userId < 1 || $reference === '') {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $kind = $type === PaypalCheckoutService::TYPE_WALLET_DEPOSIT
            ? PaypalPaymentNotCompleted::KIND_DEPOSIT
            : PaypalPaymentNotCompleted::KIND_CHECKOUT;

        $this->notifyNotCompleted($user, $kind, $reference, $reason);
    }

    public static function reasonFromCaptureException(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'declin') || str_contains($message, 'denied')
            || str_contains($message, 'not completed')) {
            return PaypalPaymentNotCompleted::REASON_DECLINED;
        }

        return PaypalPaymentNotCompleted::REASON_ERROR;
    }

    private function alreadySettled(User $user, string $kind, string $referenceCode): bool
    {
        if ($kind === PaypalPaymentNotCompleted::KIND_DEPOSIT) {
            return DepositRequest::query()
                ->where('user_id', $user->id)
                ->where('reference_code', $referenceCode)
                ->whereIn('status', ['completed', 'approved'])
                ->exists();
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'paypal')
            ->where('payment_status', 'paid')
            ->exists();
    }
}
