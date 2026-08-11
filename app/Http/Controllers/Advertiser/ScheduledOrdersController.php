<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\Orders\OrderRefundService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;

class ScheduledOrdersController extends Controller
{
    public function __construct(
        private ScheduledOrderService $scheduler,
        private OrderRefundService $refunds,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) auth()->id();
        $tab = $request->get('tab', 'upcoming');
        if (! in_array($tab, ['upcoming', 'with_publisher', 'history'], true)) {
            $tab = 'upcoming';
        }

        $counts = [
            'upcoming' => $this->scheduler->upcomingCount($userId),
            'with_publisher' => $this->scheduler->withPublisherQuery($userId)->count(),
            'history' => $this->scheduler->historyQuery($userId)->count(),
        ];

        $query = match ($tab) {
            'with_publisher' => $this->scheduler->withPublisherQuery($userId),
            'history' => $this->scheduler->historyQuery($userId),
            default => $this->scheduler->upcomingQuery($userId),
        };

        $orders = $query->with('items')->paginate(15)->withQueryString();
        $maxMonths = $this->scheduler->maxMonths();
        $maxDate = $this->scheduler->maxScheduleDateString();
        $timezones = $this->scheduler->commonTimezones();
        $editable = $tab === 'upcoming';

        return view('advertiser.scheduled-orders', compact(
            'orders',
            'tab',
            'counts',
            'maxMonths',
            'maxDate',
            'timezones',
            'editable'
        ));
    }

    public function update(Request $request, Order $order)
    {
        abort_unless((int) $order->user_id === (int) auth()->id(), 403);
        abort_unless(($order->publication_mode ?? '') === 'scheduled', 422, 'Only scheduled orders can be updated.');

        $order->refresh();
        $action = $request->input('action');

        if ($action === 'publish_now') {
            try {
                $this->scheduler->publishImmediately($order);
            } catch (\Throwable $e) {
                return back()->with('error', UserFacingError::message($e, 'Could not publish this order right now. Please try again.'));
            }

            return back()->with('success', 'Released to the publisher now — they’ve been notified to publish.');
        }

        if ($action === 'cancel') {
            try {
                $result = $this->scheduler->cancelUpcoming($order, $this->refunds);
            } catch (\Throwable $e) {
                return back()->with('error', UserFacingError::message($e, 'Could not cancel this scheduled order. Please try again.'));
            }

            $message = 'Scheduled order cancelled.';
            if (($result['released_articles'] ?? 0) > 0) {
                $message .= ' Your article is available in Content Library again.';
            }
            if (! empty($result['refunded'])) {
                $message .= ' €'.number_format((float) $result['total_amount'], 2).' was returned to your wallet balance.';
            }

            return back()->with('success', $message);
        }

        // Default / reschedule
        $data = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $schedule = $this->scheduler->normalizeSchedule(
            'scheduled',
            $data['scheduled_date'],
            $data['scheduled_time'] ?? '09:00',
            $data['timezone'] ?? $order->schedule_timezone,
        );

        if (! $schedule['ok']) {
            return back()->with('error', $schedule['message']);
        }

        try {
            $this->scheduler->reschedule($order, $schedule['at'], $schedule['timezone']);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not update the publication schedule. Please try again.'));
        }

        return back()->with('success', 'Publication schedule updated.');
    }
}
