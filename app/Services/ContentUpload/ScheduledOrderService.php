<?php

namespace App\Services\ContentUpload;

use App\Models\Order;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScheduledOrderService
{
    public function __construct(
        private ContentUploadService $uploads,
        private EmailNotificationService $emails,
        private InAppNotificationService $inApp,
    ) {}

    public function maxMonths(): int
    {
        $cfg = $this->uploads->effectiveConfig();

        return max(1, (int) ($cfg['scheduling']['max_months'] ?? 3));
    }

    public function maxScheduleAt(?Carbon $from = null, ?string $timezone = null): Carbon
    {
        $tz = $timezone ?: config('app.timezone', 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        return ($from ?? now())
            ->copy()
            ->timezone($tz)
            ->addMonthsNoOverflow($this->maxMonths())
            ->endOfDay()
            ->utc();
    }

    public function maxScheduleDateString(?string $timezone = null): string
    {
        $tz = $timezone ?: config('app.timezone', 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        return $this->maxScheduleAt(null, $tz)->timezone($tz)->toDateString();
    }

    public function defaultTimezone(): string
    {
        $cfg = $this->uploads->effectiveConfig();
        $tz = (string) ($cfg['scheduling']['default_timezone'] ?? 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            return 'UTC';
        }

        return $tz;
    }

    /**
     * Validate schedule fields from checkout.
     *
     * @return array{ok:bool, mode:string, at:?Carbon, timezone:string, message?:string}
     */
    public function normalizeSchedule(?string $mode, ?string $date, ?string $time, ?string $timezone): array
    {
        $cfg = $this->uploads->effectiveConfig();
        $schedulingOn = (bool) ($cfg['scheduling']['enabled'] ?? true);
        $tz = $timezone ?: $this->defaultTimezone();

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        $mode = ($mode === 'scheduled' && $schedulingOn) ? 'scheduled' : 'immediate';

        if ($mode !== 'scheduled') {
            return ['ok' => true, 'mode' => 'immediate', 'at' => null, 'timezone' => $tz];
        }

        if (! $date) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Publication date is required for scheduled orders.'];
        }

        $time = $time ?: '09:00';
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $tz)->utc();
        } catch (\Throwable) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Invalid publication date or time.'];
        }

        if ($at->lessThanOrEqualTo(now('UTC'))) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Publication must be scheduled in the future.'];
        }

        if ($at->greaterThan($this->maxScheduleAt(null, $tz))) {
            $months = $this->maxMonths();

            return [
                'ok' => false,
                'mode' => 'scheduled',
                'at' => null,
                'timezone' => $tz,
                'message' => 'Publication can be scheduled at most '.$months.' '.($months === 1 ? 'month' : 'months').' ahead.',
            ];
        }

        return ['ok' => true, 'mode' => 'scheduled', 'at' => $at, 'timezone' => $tz];
    }

    public function isUpcoming(Order $order): bool
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            return false;
        }
        if (in_array($order->status, ['cancelled', 'completed'], true)) {
            return false;
        }
        if ($order->schedule_released_at) {
            return false;
        }
        if (! $order->scheduled_publish_at) {
            return false;
        }

        // Future schedules stay upcoming. Unpaid past-due rows also stay here so
        // advertisers can still cancel — publishers never see unpaid orders.
        if ($order->scheduled_publish_at->greaterThan(now())) {
            return true;
        }

        return ($order->payment_status ?? '') !== 'paid';
    }

    public function isWithPublisher(Order $order): bool
    {
        if (in_array($order->status, ['cancelled', 'completed'], true)) {
            return false;
        }

        if (($order->publication_mode ?? '') !== 'scheduled' || ! $order->scheduled_publish_at) {
            return false;
        }

        if (($order->payment_status ?? '') !== 'paid') {
            return false;
        }

        return (bool) $order->schedule_released_at
            || $order->scheduled_publish_at->lessThanOrEqualTo(now());
    }

    public function baseScheduledQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled', 'completed']);
    }

    public function upcomingQuery(int $userId): Builder
    {
        return $this->baseScheduledQuery($userId)
            ->where('publication_mode', 'scheduled')
            ->whereNotNull('scheduled_publish_at')
            ->whereNull('schedule_released_at')
            ->where(function ($q) {
                $q->where('scheduled_publish_at', '>', now())
                    ->orWhere(function ($unpaidPastDue) {
                        $unpaidPastDue->where('scheduled_publish_at', '<=', now())
                            ->where(function ($pay) {
                                $pay->whereNull('payment_status')
                                    ->orWhere('payment_status', '!=', 'paid');
                            });
                    });
            })
            ->orderBy('scheduled_publish_at');
    }

    public function withPublisherQuery(int $userId): Builder
    {
        return $this->baseScheduledQuery($userId)
            ->where('publication_mode', 'scheduled')
            ->where('payment_status', 'paid')
            ->whereNotNull('scheduled_publish_at')
            ->where(function ($q) {
                $q->whereNotNull('schedule_released_at')
                    ->orWhere('scheduled_publish_at', '<=', now());
            })
            ->orderBy('scheduled_publish_at');
    }

    /**
     * Past scheduled activity: cancelled/completed that had a schedule stamp.
     */
    public function historyQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['cancelled', 'completed'])
            ->where(function ($q) {
                $q->whereNotNull('schedule_released_at')
                    ->orWhereNotNull('scheduled_publish_at')
                    ->orWhere('publication_mode', 'scheduled');
            })
            ->orderByDesc('updated_at');
    }

    public function upcomingCount(int $userId): int
    {
        return $this->upcomingQuery($userId)->count();
    }

    /**
     * Release due scheduled orders into the publisher queue.
     *
     * @return Collection<int, Order>
     */
    public function releaseDueOrders(): Collection
    {
        // Reminder-only: orders are already visible to publishers and charged in advance.
        $due = Order::query()
            ->with(['user', 'items.site'])
            ->where('publication_mode', 'scheduled')
            ->where('payment_status', 'paid')
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->whereNull('schedule_released_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->limit(100)
            ->get();

        $released = collect();

        foreach ($due as $order) {
            try {
                $order->update([
                    'schedule_released_at' => now(),
                ]);

                $fresh = $order->fresh(['user', 'items.site']);
                $this->notifyReleased($fresh);
                try {
                    $this->inApp->notifyScheduledPublishDue($fresh, false);
                } catch (\Throwable $e) {
                    Log::warning('Schedule-due bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
                $released->push($order);
            } catch (\Throwable $e) {
                Log::error('Failed releasing scheduled order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    /**
     * Send 24h reminders for upcoming scheduled publications.
     */
    public function sendUpcomingReminders(): int
    {
        $cfg = $this->uploads->effectiveConfig();
        $hours = max(1, (int) ($cfg['scheduling']['reminder_hours_before'] ?? 24));
        $windowStart = now();
        $windowEnd = now()->addHours($hours);

        $orders = Order::query()
            ->with(['user', 'items.site'])
            ->where('publication_mode', 'scheduled')
            ->where('payment_status', 'paid')
            ->whereNull('schedule_reminder_sent_at')
            ->whereNull('schedule_released_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereBetween('scheduled_publish_at', [$windowStart, $windowEnd])
            ->limit(100)
            ->get();

        $sent = 0;
        foreach ($orders as $order) {
            try {
                if ($order->user) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'status',
                        previousValue: 'scheduled',
                        newValue: 'scheduled',
                        description: 'Reminder: your scheduled publication begins within 24 hours.',
                    );
                }
                try {
                    $this->inApp->notifyScheduledPublishDue($order, true);
                } catch (\Throwable $e) {
                    Log::warning('Schedule-reminder bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
                $order->update(['schedule_reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Scheduled reminder failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Advertiser "Publish now" — release early (same as due release) and nudge publishers.
     */
    public function publishImmediately(Order $order): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            throw new \RuntimeException('Only scheduled orders can be published now.');
        }

        if (($order->payment_status ?? '') !== 'paid') {
            throw new \RuntimeException('Only paid scheduled orders can be released to the publisher.');
        }

        if (! $this->isUpcoming($order)) {
            throw new \RuntimeException('Only upcoming scheduled orders can be published now. This order is already with the publisher.');
        }

        $order->update([
            'schedule_released_at' => now(),
        ]);

        $fresh = $order->fresh(['user', 'items.site']);
        $this->notifyReleased($fresh);
        try {
            $this->inApp->notifyScheduledPublishDue($fresh, false);
        } catch (\Throwable $e) {
            Log::warning('Publish-now bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    public function cancelSchedule(Order $order): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            return;
        }

        $order->update([
            'status' => 'cancelled',
            'schedule_released_at' => null,
        ]);
    }

    public function assertCancellable(Order $order): void
    {
        if (! $this->isUpcoming($order)) {
            throw new \RuntimeException('Only upcoming scheduled orders can be cancelled. This order is already with the publisher.');
        }
    }

    public function reschedule(Order $order, Carbon $atUtc, string $timezone): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            throw new \RuntimeException('Only scheduled orders can be rescheduled.');
        }

        if (! $this->isUpcoming($order)) {
            throw new \RuntimeException('Only upcoming scheduled orders can be rescheduled. This order is already with the publisher.');
        }

        if ($atUtc->lessThanOrEqualTo(now('UTC')) || $atUtc->greaterThan($this->maxScheduleAt(null, $timezone))) {
            $months = $this->maxMonths();
            throw new \InvalidArgumentException(
                'Publication date must be in the future and within '.$months.' '.($months === 1 ? 'month' : 'months').'.'
            );
        }

        $order->update([
            'scheduled_publish_at' => $atUtc,
            'schedule_timezone' => $timezone,
            'schedule_reminder_sent_at' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    public function commonTimezones(): array
    {
        return [
            'UTC',
            'Europe/London',
            'Europe/Berlin',
            'Europe/Paris',
            'Europe/Amsterdam',
            'Europe/Madrid',
            'Europe/Rome',
            'Europe/Warsaw',
            'Europe/Athens',
            'Europe/Bucharest',
            'Europe/Istanbul',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'Asia/Dubai',
            'Asia/Kolkata',
            'Asia/Singapore',
            'Australia/Sydney',
        ];
    }

    protected function notifyReleased(Order $order): void
    {
        try {
            $this->emails->notifyOrderLifecycle(
                order: $order,
                changeKind: 'status',
                previousValue: 'scheduled',
                newValue: (string) $order->status,
                description: 'Scheduled publication date has arrived. Please publish the article today.',
            );
        } catch (\Throwable $e) {
            Log::warning('Schedule-date reminder email failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
