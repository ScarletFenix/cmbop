<?php

namespace App\Services;

use App\Mail\AdminManualPaymentNotification;
use App\Mail\AdminNewUserRegistered;
use App\Mail\DepositReminderMail;
use App\Mail\GoogleTempPasswordMail;
use App\Mail\MonthlySpendingSummary;
use App\Mail\NewSiteNotification;
use App\Mail\OrderStatusChanged;
use App\Mail\PlatformMailable;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\TrustpilotReviewRequest;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Mail\WithdrawalRequestNotification;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central entry point for NEW / gap-fill notifications.
 * Does not replace existing controller Mail::send calls — those keep working
 * via PlatformMailable policy checks.
 */
class EmailNotificationService
{
    public function sendWelcome(User $user): void
    {
        $this->dispatch('welcome', $user, new WelcomeEmail($user), 'welcome:user:'.$user->id);
    }

    public function sendGoogleTempPassword(User $user, string $temporaryPassword): void
    {
        $this->dispatch(
            'google_temp_password',
            $user,
            new GoogleTempPasswordMail($user, $temporaryPassword),
            'google_temp_password:user:'.$user->id
        );
    }

    public function sendTrustpilotReview(User $user, ?Order $order = null): void
    {
        $key = 'trustpilot:user:'.$user->id.':order:'.($order?->id ?? 'none');
        $this->dispatch('trustpilot_review', $user, new TrustpilotReviewRequest($user, $order), $key);
    }

    public function notifyAdminsNewUser(User $user): void
    {
        foreach ($this->adminUsers() as $admin) {
            $this->dispatch(
                'admin_new_user',
                $admin,
                new AdminNewUserRegistered($user, $admin),
                'admin_new_user:'.$user->id.':admin:'.$admin->id
            );
        }

        $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
        if ($this->adminUsers()->isEmpty() && filled($fallback)) {
            try {
                $mailable = new AdminNewUserRegistered($user, null);
                $mailable->notificationType = 'admin_new_user';
                $mailable->dedupeKey = 'admin_new_user:'.$user->id.':fallback';
                Mail::to($fallback)->send($mailable);
            } catch (\Throwable $e) {
                Log::warning('Fallback admin new-user email failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(InAppNotificationService::class)->notifyAdminsNewUser($user);
        } catch (\Throwable $e) {
            Log::warning('Admin new-user bell notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publisher submitted/updated a site that needs admin review.
     * Bell always runs even when mail fails or no admin mailbox is configured.
     */
    public function notifyAdminsNewSite(Site $site, string $action = 'create', bool $sendEmail = true): void
    {
        $site->loadMissing('publisher');

        if ($sendEmail) {
            $admins = $this->adminUsers();
            foreach ($admins as $admin) {
                $this->dispatch(
                    'new_site',
                    $admin,
                    new NewSiteNotification($site, $action),
                    'new_site:'.$action.':'.$site->id.':admin:'.$admin->id
                );
            }

            $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
            if ($admins->isEmpty() && filled($fallback)) {
                try {
                    $mailable = new NewSiteNotification($site, $action);
                    $mailable->notificationType = 'new_site';
                    $mailable->dedupeKey = 'new_site:'.$action.':'.$site->id.':fallback';
                    $mailable->skipUserPreference = true;
                    Mail::to($fallback)->send($mailable);
                } catch (\Throwable $e) {
                    Log::warning('Fallback admin new-site email failed', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        try {
            app(InAppNotificationService::class)->notifyAdminsNewSite($site, $action);
        } catch (\Throwable $e) {
            Log::warning('Admin new-site bell notification failed', [
                'site_id' => $site->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Role-pivot admin users (not active_role_id alone).
     *
     * @return Collection<int, User>
     */
    public function staffAdminUsers(): Collection
    {
        return $this->adminUsers();
    }

    /**
     * New withdrawal request — email role-pivot admins; bell always runs after mail attempts.
     */
    public function notifyAdminsWithdrawalRequested(Withdrawal $withdrawal, ?User $requester = null): void
    {
        $requester = $requester ?: User::query()->find($withdrawal->user_id);
        if (! $requester) {
            $requester = new User(['name' => 'User', 'email' => 'unknown@example.com']);
        }

        $admins = $this->adminUsers();
        foreach ($admins as $admin) {
            $this->dispatch(
                'withdrawal_request',
                $admin,
                new WithdrawalRequestNotification($withdrawal, $requester),
                'withdrawal_request:'.$withdrawal->id.':admin:'.$admin->id
            );
        }

        $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
        if ($admins->isEmpty() && filled($fallback)) {
            try {
                $mailable = new WithdrawalRequestNotification($withdrawal, $requester);
                $mailable->notificationType = 'withdrawal_request';
                $mailable->dedupeKey = 'withdrawal_request:'.$withdrawal->id.':fallback';
                $mailable->skipUserPreference = true;
                Mail::to($fallback)->send($mailable);
            } catch (\Throwable $e) {
                Log::warning('Fallback admin withdrawal email failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(InAppNotificationService::class)->notifyAdminsWithdrawalRequested($withdrawal, $requester);
        } catch (\Throwable $e) {
            Log::warning('Admin withdrawal bell notification failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Advertiser marked manual payment — email role-pivot admins; bell always runs after mail attempts.
     *
     * @param  iterable<int, Order>  $orders
     */
    public function notifyAdminsManualPayment(User $customer, iterable $orders, string $paymentMethod): void
    {
        $orderList = collect($orders)->values();
        if ($orderList->isEmpty()) {
            return;
        }

        $totalAmount = (float) $orderList->sum(fn (Order $o) => (float) $o->total_amount);
        $admins = $this->adminUsers();

        foreach ($admins as $admin) {
            $this->dispatch(
                'admin_manual_payment',
                $admin,
                new AdminManualPaymentNotification($customer, $orderList->all(), $paymentMethod, $totalAmount),
                'admin_manual_payment:'.$orderList->pluck('id')->implode('-').':admin:'.$admin->id
            );
        }

        $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
        if ($admins->isEmpty() && filled($fallback)) {
            try {
                $mailable = new AdminManualPaymentNotification($customer, $orderList->all(), $paymentMethod, $totalAmount);
                $mailable->notificationType = 'admin_manual_payment';
                $mailable->dedupeKey = 'admin_manual_payment:'.$orderList->pluck('id')->implode('-').':fallback';
                $mailable->skipUserPreference = true;
                Mail::to($fallback)->send($mailable);
            } catch (\Throwable $e) {
                Log::warning('Fallback admin manual-payment email failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(InAppNotificationService::class)->notifyAdminsManualPayment($customer, $orderList, $paymentMethod);
        } catch (\Throwable $e) {
            Log::warning('Admin manual-payment bell notification failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendWeeklySummary(User $user, array $payload): void
    {
        $this->dispatch(
            'weekly_activity_summary',
            $user,
            new WeeklyActivitySummary($user, $payload),
            'weekly_summary:'.$user->id.':'.($payload['week_key'] ?? now()->format('o-\WW'))
        );
    }

    public function sendMonthlySummary(User $user, array $payload): void
    {
        $this->dispatch(
            'monthly_spending_summary',
            $user,
            new MonthlySpendingSummary($user, $payload),
            'monthly_summary:'.$user->id.':'.($payload['month_key'] ?? now()->format('Y-m'))
        );
    }

    public function sendPublisherAddSiteReminder(User $user, string $step): void
    {
        $step = strtolower($step);
        if (! in_array($step, [
            PublisherAddSiteReminderMail::STEP_DAY3,
            PublisherAddSiteReminderMail::STEP_DAY7,
        ], true)) {
            return;
        }

        $this->dispatch(
            'publisher_add_site_reminder',
            $user,
            new PublisherAddSiteReminderMail($user, $step),
            'publisher_add_site:'.$step.':'.$user->id
        );
    }

    /**
     * Day-7 / day-14 nudge for advertisers who never funded their wallet.
     */
    public function sendDepositReminder(User $user, string $step): void
    {
        $step = strtolower($step);
        if (! in_array($step, [
            DepositReminderMail::STEP_DAY7,
            DepositReminderMail::STEP_DAY14,
        ], true)) {
            return;
        }

        $this->dispatch(
            'deposit_reminder',
            $user,
            new DepositReminderMail($user, $step),
            'deposit_reminder:'.$step.':'.$user->id
        );
    }

    /**
     * Fan-out order lifecycle email to Advertiser, Publisher(s), and Admin.
     *
     * Marketing is intentionally excluded: they cannot open admin order pages
     * (RedirectMarketingFromAdmin remaps orders/* to the marketing dashboard).
     */
    public function notifyOrderLifecycle(
        Order $order,
        string $changeKind,
        ?string $previousValue,
        string $newValue,
        ?string $description = null,
    ): void {
        if (! $this->isTypeEnabled('order_status_changed')) {
            return;
        }

        $order->loadMissing(['user', 'items.site.publisher']);
        $recipients = $this->orderLifecycleRecipients($order);

        foreach ($recipients as $row) {
            /** @var User $user */
            $user = $row['user'];
            $audience = $row['audience'];

            $dedupe = implode(':', [
                'order_status_changed',
                $order->id,
                $changeKind,
                (string) $previousValue,
                $newValue,
                $audience,
                $user->id,
            ]);

            $mailable = new OrderStatusChanged(
                order: $order,
                recipient: $user,
                audience: $audience,
                changeKind: $changeKind,
                previousValue: $previousValue,
                newValue: $newValue,
                description: $description,
            );

            // Admins always receive operational order emails
            if ($audience === 'admin') {
                $mailable->skipUserPreference = true;
            }

            $this->dispatch('order_status_changed', $user, $mailable, $dedupe);
        }
    }

    /**
     * @return array<int, array{user: User, audience: string}>
     */
    protected function orderLifecycleRecipients(Order $order): array
    {
        $rows = [];
        $seen = [];

        $add = function (?User $user, string $audience) use (&$rows, &$seen) {
            if (! $user?->id || ! $user->email) {
                return;
            }
            $key = $audience.':'.$user->id;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $rows[] = ['user' => $user, 'audience' => $audience];
        };

        $add($order->user, 'advertiser');

        // Publishers are notified immediately — including scheduled orders (publish on the date).
        foreach ($order->items as $item) {
            $publisher = $item->site?->publisher;
            if (! $publisher && $item->site?->publisher_id) {
                $publisher = User::query()->find($item->site->publisher_id);
            }
            $add($publisher, 'publisher');
        }

        foreach ($this->usersWithRole('admin') as $admin) {
            $add($admin, 'admin');
        }

        // Fallback admin inbox if no admin users
        if ($this->usersWithRole('admin')->isEmpty()) {
            $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
            if (filled($fallback)) {
                $ghost = new User(['name' => 'Admin', 'email' => $fallback]);
                $ghost->id = 0;
                // Direct send without user prefs
                try {
                    $mailable = new OrderStatusChanged(
                        order: $order,
                        recipient: $ghost,
                        audience: 'admin',
                        changeKind: 'status',
                        previousValue: null,
                        newValue: (string) $order->status,
                    );
                    $mailable->notificationType = 'order_status_changed';
                    $mailable->dedupeKey = 'order_status_changed:fallback:'.$order->id.':'.$order->status;
                    Mail::to($fallback)->send($mailable);
                } catch (\Throwable $e) {
                    Log::warning('Fallback admin order email failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $rows;
    }

    public function isTypeEnabled(string $type): bool
    {
        return EmailNotificationSetting::isEnabled($type);
    }

    public function types(): array
    {
        return config('email_notifications.types', []);
    }

    /**
     * Send a reminder that already knows its own type and dedupe key.
     *
     * The order reminder cadences derive their keys from the order item and the
     * stage they reached, so re-deriving them out here would only duplicate that
     * logic. Everything else — preference checks, logging, throttling — is
     * shared with the rest of the platform's mail.
     */
    public function sendReminder(?User $recipient, PlatformMailable $mailable): void
    {
        if (! $recipient?->email || ! $mailable->notificationType) {
            return;
        }

        $this->dispatch(
            $mailable->notificationType,
            $recipient,
            $mailable,
            $mailable->dedupeKey ?: $mailable->notificationType.':'.$recipient->id
        );
    }

    protected function dispatch(string $type, ?User $recipient, PlatformMailable $mailable, string $dedupeKey): void
    {
        if (! $recipient?->email) {
            return;
        }

        $mailable->notificationType = $type;
        $mailable->dedupeKey = $dedupeKey;
        $mailable->recipientUser = $recipient;

        try {
            Mail::to($recipient->email)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService dispatch failed', [
                'type' => $type,
                'to' => $recipient->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function adminUsers(): Collection
    {
        return $this->usersWithRole('admin');
    }

    protected function usersWithRole(string $roleName): Collection
    {
        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->whereNotNull('email')
            ->get();
    }
}
