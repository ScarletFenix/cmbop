<?php

namespace App\Services;

use App\Models\User;
use App\Services\Advertiser\AdvertiserSpendService;
use Carbon\Carbon;

class AdvertiserAnalyticsService
{
    public function __construct(private AdvertiserSpendService $spend) {}

    /**
     * Build full spending history for chart toggles (order / day / month).
     * Option A candles: bucket by paid_at; solid = completed, dim = in progress.
     */
    public function build(User $user, string $view = 'day', array $range = []): array
    {
        $candles = $this->spend->candles((int) $user->id, $view, $range);
        $summary = $candles['summary'];
        $series = $candles['series'];

        $first = collect($series)->first();
        $last = collect($series)->last();

        return [
            'has_spend' => $candles['has_spend'],
            'total_spend' => $summary['net'],
            'gross' => $summary['gross'],
            'refunded' => $summary['refunded'],
            'net' => $summary['net'],
            'spent' => $summary['spent'],
            'in_progress' => $summary['in_progress'],
            'committed' => $summary['committed'],
            'total_orders' => $summary['spent_orders'] + $summary['in_progress_orders'],
            'spent_orders' => $summary['spent_orders'],
            'in_progress_orders' => $summary['in_progress_orders'],
            'first_order_at' => isset($first['datetime'])
                ? Carbon::parse($first['datetime'])
                : (isset($first['date']) ? Carbon::parse($first['date']) : null),
            'last_order_at' => isset($last['datetime'])
                ? Carbon::parse($last['datetime'])
                : (isset($last['date']) ? Carbon::parse($last['date']) : null),
            'by_order' => $view === 'order' ? $series : [],
            'by_day' => $view === 'day' ? $series : [],
            'by_month' => $view === 'month' ? $series : [],
            'series' => $series,
            'view' => $view,
            'summary' => $summary,
        ];
    }
}
