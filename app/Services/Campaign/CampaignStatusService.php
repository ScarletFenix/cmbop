<?php

namespace App\Services\Campaign;

use App\Models\OrderItem;
use App\Models\Project;
use Illuminate\Support\Collection;

class CampaignStatusService
{
    public const BUCKETS = [
        'not_started',
        'in_progress',
        'waiting_approval',
        'needs_improvements',
        'completed',
        'rejected',
    ];

    /**
     * Aggregate placement pipeline counts for a campaign (project).
     *
     * @return array{not_started:int,in_progress:int,waiting_approval:int,needs_improvements:int,completed:int,rejected:int,total:int}
     */
    public function countsFor(Project $project): array
    {
        $counts = array_fill_keys(self::BUCKETS, 0);

        $items = OrderItem::query()
            ->whereHas('order', function ($q) use ($project) {
                $q->where('project_id', $project->id)
                    ->where('user_id', $project->user_id);
            })
            ->with(['order:id,status,payment_status'])
            ->get(['id', 'order_id', 'publisher_status', 'modification_requested']);

        foreach ($items as $item) {
            $bucket = $this->bucketFor($item);
            $counts[$bucket]++;
        }

        $counts['total'] = array_sum(array_intersect_key($counts, array_flip(self::BUCKETS)));

        return $counts;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, array{not_started:int,in_progress:int,waiting_approval:int,needs_improvements:int,completed:int,rejected:int,total:int}>
     */
    public function countsForMany(Collection $projects): array
    {
        $out = [];
        foreach ($projects as $project) {
            $out[$project->id] = $this->countsFor($project);
        }

        return $out;
    }

    public function bucketFor(OrderItem $item): string
    {
        $publisherStatus = strtolower((string) ($item->publisher_status ?? 'pending'));
        $orderStatus = strtolower((string) ($item->order?->status ?? 'pending'));
        $modification = strtolower((string) ($item->modification_requested ?? ''));

        if ($publisherStatus === 'rejected' || $orderStatus === 'cancelled') {
            return 'rejected';
        }

        if ($publisherStatus === 'completed' || $orderStatus === 'completed') {
            return 'completed';
        }

        if ($modification === 'yes' || $modification === '1') {
            return 'needs_improvements';
        }

        if ($orderStatus === 'review') {
            return 'waiting_approval';
        }

        if ($publisherStatus === 'accepted' || in_array($orderStatus, ['processing', 'scheduled'], true)) {
            return 'in_progress';
        }

        return 'not_started';
    }

    /**
     * Site IDs already ordered for this campaign (paid or in-flight, not cancelled/rejected).
     *
     * @return array<int, int>
     */
    public function orderedSiteIds(Project $project): array
    {
        return OrderItem::query()
            ->whereHas('order', function ($q) use ($project) {
                $q->where('project_id', $project->id)
                    ->where('user_id', $project->user_id)
                    ->where('status', '!=', 'cancelled')
                    ->where(function ($payment) {
                        $payment->where('payment_status', 'paid')
                            ->orWhereIn('payment_method', ['wise', 'crypto', 'bank', 'card', 'wallet']);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('publisher_status')
                    ->orWhere('publisher_status', '!=', 'rejected');
            })
            ->whereNotNull('site_id')
            ->distinct()
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
