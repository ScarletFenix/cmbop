<?php

namespace App\Services\WeeklyNewSites;

use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\User;
use App\Models\UserConsent;
use App\Support\MailAddress;
use App\Support\SmtpFailure;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing eligibility for the weekly new-site digest.
 *
 * Fail closed: unknown permission is not a grant. A paid order is never
 * written as consent. Two independent bases can grant:
 *
 * 1. Recorded consent — latest user_consents.marketing_consent = true, or an
 *    explicit weekly_new_sites preference row with enabled = true.
 * 2. PECR existing-customer (similar products) — a qualifying paid placement
 *    AND a user_consents row (proof the registration marketing checkbox was
 *    shown as an opportunity to refuse) AND no later opt-out. Unticked GDPR
 *    opt-in is not treated as PECR refusal; explicit unsubscribe is.
 *
 * Global marketing_emails = false, topic = false, invalid address, and
 * permanent SMTP failures already logged for the address all block.
 */
class PermissionGate
{
    public const TYPE = 'weekly_new_sites';

    public const PREFERENCE = 'weekly_new_sites';

    public const BASIS_RECORDED_CONSENT = 'recorded_consent';

    public const BASIS_TOPIC_OPT_IN = 'topic_opt_in';

    public const BASIS_PECR = 'pecr_existing_customer';

    /**
     * @return array{allowed: bool, basis: ?string, reason: ?string}
     */
    public function inspect(?User $user): array
    {
        if (! $user || ! $user->id) {
            return $this->deny('no_user');
        }

        if (! MailAddress::isValid($user->email)) {
            return $this->deny('invalid_address');
        }

        if (! EmailNotificationSetting::isEnabled(self::TYPE)) {
            return $this->deny('admin_disabled');
        }

        if ($this->explicitlyDisabled($user, 'marketing_emails')) {
            return $this->deny('marketing_unsubscribed');
        }

        if ($this->explicitlyDisabled($user, self::PREFERENCE)) {
            return $this->deny('topic_unsubscribed');
        }

        if ($this->addressPermanentlyFailed($user->email)) {
            return $this->deny('permanent_bounce');
        }

        if ($this->explicitlyEnabled($user, self::PREFERENCE)) {
            return $this->allow(self::BASIS_TOPIC_OPT_IN);
        }

        $consent = $this->latestConsent($user);
        if ($consent && $consent->marketing_consent === true) {
            return $this->allow(self::BASIS_RECORDED_CONSENT);
        }

        if ($this->hasQualifyingPaidPlacement($user) && $consent) {
            return $this->allow(self::BASIS_PECR);
        }

        if (! $consent) {
            return $this->deny('unknown_permission');
        }

        return $this->deny('no_permission');
    }

    public function allows(?User $user): bool
    {
        return $this->inspect($user)['allowed'];
    }

    public function hasQualifyingPaidPlacement(User $user): bool
    {
        if (! Schema::hasTable('orders')) {
            return false;
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->whereIn('payment_status', ['paid', 'completed'])
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['cancelled', 'canceled']);
            })
            ->when(
                Schema::hasColumn('orders', 'is_sandbox'),
                fn ($q) => $q->where(function ($inner) {
                    $inner->whereNull('is_sandbox')->orWhere('is_sandbox', 0);
                })
            )
            ->exists();
    }

    public function latestConsent(User $user): ?UserConsent
    {
        if (! Schema::hasTable('user_consents')) {
            return null;
        }

        return UserConsent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();
    }

    public function explicitlyDisabled(User $user, string $key): bool
    {
        $row = $this->preferenceRow($user, $key);

        return $row !== null && ! $row;
    }

    public function explicitlyEnabled(User $user, string $key): bool
    {
        $row = $this->preferenceRow($user, $key);

        return $row === true;
    }

    /**
     * Null = no stored row (unknown). True/false = stored value.
     */
    public function preferenceRow(User $user, string $key): ?bool
    {
        if (! Schema::hasTable('email_notification_preferences')) {
            return null;
        }

        $row = EmailNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('preference_key', $key)
            ->first();

        if (! $row) {
            return null;
        }

        return (bool) $row->enabled;
    }

    public function addressPermanentlyFailed(string $email): bool
    {
        if (! Schema::hasTable('email_logs')) {
            return false;
        }

        $errors = EmailLog::query()
            ->where('to_email', $email)
            ->where('status', EmailLog::STATUS_FAILED)
            ->whereNotNull('error')
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('error');

        foreach ($errors as $error) {
            if (! is_string($error) || $error === '') {
                continue;
            }
            if (SmtpFailure::isPermanent(new \RuntimeException($error))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{allowed: bool, basis: ?string, reason: ?string}
     */
    private function allow(string $basis): array
    {
        return ['allowed' => true, 'basis' => $basis, 'reason' => null];
    }

    /**
     * @return array{allowed: bool, basis: ?string, reason: ?string}
     */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'basis' => null, 'reason' => $reason];
    }
}
