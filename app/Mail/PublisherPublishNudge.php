<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The publisher accepted but has not published.
 *
 * Deliberately holds a collection rather than a single item: a publisher late on
 * several orders gets one email listing them instead of one per order, which is
 * the difference between a useful reminder and something they mute.
 *
 * @phpstan-type NudgeRow array{order_id?: int, order_number: string, site_name: string, due_at: \Illuminate\Support\Carbon, hours_overdue: int, promised: string, payout: float}
 */
class PublisherPublishNudge extends PlatformMailable
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public User $publisher,
        public Collection $rows,
        public int $stage,
        public string $dedupeSuffix,
    ) {
        parent::__construct();

        $this->notificationType = 'publisher_publish_nudge';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'publisher_publish_nudge:'.$publisher->id.':'.$dedupeSuffix;
    }

    public function build()
    {
        $batched = $this->rows->count() > 1;
        $first = $this->rows->first();
        $focusOrderId = (int) ($first['order_id'] ?? 0);

        return $this->subject($this->subjectLine($batched, $first))
            ->markdown('emails.publisher.publish-nudge', [
                'firstName' => $this->firstName($this->publisher),
                'rows' => $this->rows,
                'batched' => $batched,
                'stage' => $this->stage,
                // Batched digests still open the lead (worst) order when known.
                'tasksUrl' => $this->publisherTasksUrl($focusOrderId > 0 ? $focusOrderId : null),
                'brand' => $this->brand(),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $first
     */
    private function subjectLine(bool $batched, ?array $first): string
    {
        if ($batched) {
            return $this->rows->count().' orders are waiting to be published';
        }

        $order = $first['order_number'] ?? '';

        // Stage 1 fires before the deadline, so it must not read as a telling-off.
        return match (true) {
            $this->stage <= 1 => 'Due soon: publish order #'.$order,
            $this->stage === 2 => 'Overdue: order #'.$order.' is past its turnaround',
            $this->stage === 3 => 'Still overdue: order #'.$order,
            default => 'Final notice: order #'.$order.' needs publishing',
        };
    }
}
