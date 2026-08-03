<?php

namespace App\Console\Commands;

use App\Mail\AdminStalledOrderAlert;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\Reminders\OrderDeadline;
use App\Services\Reminders\ReminderFatigueGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Chases publishers so advertisers get their links sooner.
 *
 * Two independent tracks, because the two delays have different causes and need
 * different words: an order sitting unaccepted (nothing is happening) and an
 * accepted order that has not been published (something is late).
 *
 * The publish track is anchored on the turnaround the publisher advertised on
 * their own site rather than a flat interval, so nobody inside their stated SLA
 * is nagged and the email can hold them to their own promise.
 */
class NudgePublishers extends Command
{
    protected $signature = 'orders:nudge-publishers {--dry-run : Report what would be sent without sending}';

    protected $description = 'Remind publishers to accept and publish orders, escalating to admin when they stall';

    private ReminderFatigueGuard $guard;

    private OrderDeadline $deadlines;

    private bool $dryRun = false;

    public function handle(
        ReminderFatigueGuard $guard,
        OrderDeadline $deadlines,
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
    ): int {
        $this->guard = $guard;
        $this->deadlines = $deadlines;
        $this->dryRun = (bool) $this->option('dry-run');

        if (! Schema::hasColumn('order_items', 'publish_nudge_stage')) {
            $this->warn('Reminder tracking columns missing — run migrations first.');

            return Command::SUCCESS;
        }

        $accepts = $this->nudgeUnaccepted($mailer, $bells);
        $publishes = $this->nudgeUnpublished($mailer, $bells);

        $this->info(sprintf(
            '%sPublisher nudges — accept: %d, publish: %d',
            $this->dryRun ? '[dry run] ' : '',
            $accepts,
            $publishes
        ));

        return Command::SUCCESS;
    }

    /**
     * Track 1: paid orders the publisher has not accepted.
     */
    private function nudgeUnaccepted(EmailNotificationService $mailer, InAppNotificationService $bells): int
    {
        $stages = (array) config('reminders.publisher_accept.stages_hours', [12, 36, 72]);
        $adminFrom = (int) config('reminders.publisher_accept.admin_alert_from_stage', 3);
        $sent = 0;

        $items = OrderItem::query()
            ->whereNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('live_url')->orWhere('live_url', ''))
            ->where('accept_nudge_stage', '<', count($stages))
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid')->where('status', 'pending');
            })
            ->with('order')
            ->limit(300)
            ->get();

        foreach ($items as $item) {
            try {
                $order = $item->order;
                $paidAt = $order?->paid_at ?? $order?->created_at;

                if (! $order || ! $paidAt) {
                    continue;
                }

                $hoursWaiting = (int) $paidAt->diffInHours(now());
                $nextStage = ((int) $item->accept_nudge_stage) + 1;
                $threshold = (int) ($stages[$nextStage - 1] ?? 0);

                if ($hoursWaiting < $threshold) {
                    continue;
                }

                $site = $item->site_id ? Site::find($item->site_id) : null;
                $publisher = $site?->publisher_id ? User::find($site->publisher_id) : null;

                if (! $publisher) {
                    continue;
                }

                if (! $this->guard->allows($publisher)) {
                    $this->line('- skipped (daily cap) publisher #'.$publisher->id);

                    continue;
                }

                if ($this->dryRun) {
                    $this->line("- would send accept nudge {$nextStage} for order #{$order->order_number}");
                    $sent++;

                    continue;
                }

                // Record the stage before sending: a mail failure must not put
                // the item back in the queue for the same stage next run.
                $item->update([
                    'accept_nudge_stage' => $nextStage,
                    'accept_nudge_sent_at' => now(),
                ]);

                $mailer->sendReminder(
                    $publisher,
                    new PublisherAcceptNudge($publisher, $order, $item, $site, $nextStage, $hoursWaiting)
                );
                $this->guard->record($publisher);

                $this->bell($bells, fn () => $bells->notifyPublisherAcceptNudge($order, $item, $publisher, $nextStage));

                if ($nextStage >= $adminFrom) {
                    $this->alertAdmins($mailer, $bells, $order, $item, $site, $publisher, $nextStage, $hoursWaiting, 'accept');
                }

                $sent++;
                $this->info("✓ Accept nudge {$nextStage} for order #{$order->order_number}");
            } catch (\Throwable $e) {
                Log::error('Publisher accept nudge failed', [
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Track 2: accepted orders that are due soon or overdue.
     *
     * Collected per publisher first so someone late on several orders gets one
     * email rather than one per order.
     */
    private function nudgeUnpublished(EmailNotificationService $mailer, InAppNotificationService $bells): int
    {
        $overdueStages = (array) config('reminders.publisher_publish.overdue_stages_hours', [24, 72, 168, 336]);
        $maxStage = count($overdueStages) + 1;
        $adminFrom = (int) config('reminders.publisher_publish.admin_alert_from_stage', 3);
        $batchThreshold = max(2, (int) config('reminders.publisher_publish.batch_threshold', 3));

        $items = OrderItem::query()
            ->whereNotNull('accepted_at')
            ->where(fn ($q) => $q->whereNull('live_url')->orWhere('live_url', ''))
            ->where('publish_nudge_stage', '<', $maxStage)
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid')->whereIn('status', ['processing', 'pending']);
            })
            ->with(['order', 'site'])
            ->limit(500)
            ->get();

        /** @var Collection<int, array<string, mixed>> $due */
        $due = collect();

        foreach ($items as $item) {
            $order = $item->order;
            $site = $item->site;

            if (! $order || ! $site || ! $site->publisher_id) {
                continue;
            }

            $deadline = $this->deadlines->for($item, $order, $site);

            if (! $deadline) {
                // No turnaround on the listing means no promise to hold them to.
                continue;
            }

            $stage = $this->stageFor($item, $deadline, $overdueStages);

            if ($stage === null) {
                continue;
            }

            $hoursOverdue = max(0, (int) $deadline->diffInHours(now(), false));

            $due->push([
                'item' => $item,
                'order' => $order,
                'site' => $site,
                'publisher_id' => (int) $site->publisher_id,
                'stage' => $stage,
                'deadline' => $deadline,
                'hours_overdue' => $hoursOverdue,
            ]);
        }

        $sent = 0;

        foreach ($due->groupBy('publisher_id') as $publisherId => $group) {
            $publisher = User::find($publisherId);

            if (! $publisher?->email) {
                continue;
            }

            if (! $this->guard->allows($publisher)) {
                $this->line('- skipped (daily cap) publisher #'.$publisherId);

                continue;
            }

            // Lead with the latest order so the tone matches the worst case.
            $group = $group->sortByDesc('stage')->values();
            $batched = $group->count() >= $batchThreshold;
            $selected = $batched ? $group : $group->take(1);
            $topStage = (int) $selected->max('stage');

            if ($this->dryRun) {
                $this->line("- would send publish nudge stage {$topStage} to publisher #{$publisherId} ({$selected->count()} item(s))");
                $sent++;

                continue;
            }

            try {
                $rows = $selected->map(fn (array $row) => $this->row($row));

                foreach ($selected as $row) {
                    $row['item']->update([
                        'publish_nudge_stage' => $row['stage'],
                        'publish_nudge_sent_at' => now(),
                    ]);
                }

                $suffix = $selected->count() > 1
                    ? 'batch:'.$topStage.':'.$selected->pluck('item.id')->implode('-')
                    : $selected->first()['item']->id.':'.$topStage;

                $mailer->sendReminder(
                    $publisher,
                    new PublisherPublishNudge($publisher, $rows, $topStage, $suffix)
                );
                $this->guard->record($publisher);

                foreach ($selected as $row) {
                    $this->bell($bells, fn () => $bells->notifyPublisherPublishNudge(
                        $row['order'],
                        $row['item'],
                        $publisher,
                        (int) $row['stage'],
                        (int) $row['hours_overdue'],
                    ));

                    if ($row['stage'] >= $adminFrom) {
                        $this->alertAdmins(
                            $mailer,
                            $bells,
                            $row['order'],
                            $row['item'],
                            $row['site'],
                            $publisher,
                            (int) $row['stage'],
                            (int) $row['hours_overdue'],
                            'publish'
                        );
                    }
                }

                $sent++;
                $this->info("✓ Publish nudge stage {$topStage} to publisher #{$publisherId} ({$selected->count()} item(s))");
            } catch (\Throwable $e) {
                Log::error('Publisher publish nudge failed', [
                    'publisher_id' => $publisherId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * The stage this item has earned, or null when nothing is due yet.
     *
     * Stage 1 is the pre-deadline warning; stage 2+ map to hours past the
     * deadline. Returns at most one stage per run per item so a long outage
     * cannot fire four emails at once.
     *
     * @param  array<int, int|string>  $overdueStages
     */
    private function stageFor(OrderItem $item, Carbon $deadline, array $overdueStages): ?int
    {
        $current = (int) $item->publish_nudge_stage;
        $hoursPast = (int) $deadline->diffInHours(now(), false);

        $earned = 1;
        foreach (array_values($overdueStages) as $index => $threshold) {
            if ($hoursPast >= (int) $threshold) {
                $earned = $index + 2;
            }
        }

        if ($hoursPast < 0) {
            // Still inside the window: only the pre-deadline warning applies,
            // and only once the remaining time drops below the trigger.
            $total = max(1, (int) $item->accepted_at?->diffInHours($deadline) ?: 1);
            $fraction = (float) config('reminders.publisher_publish.due_soon_fraction', 0.25);
            $minHours = (int) config('reminders.publisher_publish.due_soon_min_hours', 12);
            $trigger = max($minHours, (int) round($total * $fraction));

            if (abs($hoursPast) > $trigger) {
                return null;
            }

            $earned = 1;
        }

        if ($earned <= $current) {
            return null;
        }

        // Advance one stage at a time.
        return $current + 1;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        /** @var OrderItem $item */
        $item = $row['item'];
        /** @var Site $site */
        $site = $row['site'];
        /** @var Carbon $deadline */
        $deadline = $row['deadline'];
        $hoursOverdue = (int) $row['hours_overdue'];

        return [
            'order_number' => (string) ($row['order']->order_number ?? $row['order']->id),
            'site_name' => (string) ($site->site_name ?: $item->site_name ?: 'your site'),
            'due_at' => $deadline,
            'hours_overdue' => $hoursOverdue,
            'overdue_label' => $this->overdueLabel($hoursOverdue),
            'promised' => (string) ($site->turnaround_time ?: 'listed'),
            'payout' => (float) $item->publisherPayoutAmount(),
        ];
    }

    private function overdueLabel(int $hours): string
    {
        if ($hours < 1) {
            return 'due now';
        }

        if ($hours < 48) {
            return $hours.'h late';
        }

        return (int) round($hours / 24).' days late';
    }

    private function alertAdmins(
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
        Order $order,
        OrderItem $item,
        ?Site $site,
        ?User $publisher,
        int $stage,
        int $hoursOverdue,
        string $track,
    ): void {
        try {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->get();

            foreach ($admins as $admin) {
                if (! $admin->email) {
                    continue;
                }

                // Admin escalations bypass the fatigue cap: they are work items,
                // not marketing, and there are only a handful of admins.
                $mailer->sendReminder(
                    $admin,
                    new AdminStalledOrderAlert($order, $item, $site, $publisher, $stage, $hoursOverdue, $track)
                );
            }

            $this->bell($bells, fn () => $bells->notifyAdminsStalledOrder($order, $item, $publisher, $track, $hoursOverdue));
        } catch (\Throwable $e) {
            Log::warning('Admin stalled-order alert failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bell(InAppNotificationService $bells, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Reminder bell failed', ['error' => $e->getMessage()]);
        }
    }
}
