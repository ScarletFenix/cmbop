<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ActivityLogDateBounds;
use App\Support\ActivityLogTextSearch;
use App\Support\MarketingOpsQueues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    /** @var list<string> */
    public const TRACKED_ACTIONS = [
        'bulk_request.done',
        'bulk_request.seeded',
        'bulk_request.sheet_sent',
        'bulk_request.cancelled',
        'bulk_request.items_rejected',
        'bulk_request.notes_updated',
        'site.deleted_by_marketing',
        'site.updated',
        'site.activated',
        'site.approved',
        'site.deactivated',
        'site.assigned_for_acceptance',
        'site.image_uploaded',
        'site.metrics_refreshed',
        'site.metrics_refresh_queued',
        'site.screenshot_refreshed',
        'site.screenshot_refresh_queued',
        'site.metrics_manual',
    ];

    public function dashboard()
    {
        $userId = (int) auth()->id();

        [$todayStart, $todayEnd] = ActivityLogDateBounds::todayBounds();

        $stats = [
            'ready_to_activate' => MarketingOpsQueues::sitesReadyForStaffCount(),
            'bulk_waiting_on_you' => MarketingOpsQueues::bulkWaitingOnMarketerCount(),
            'sites_waiting_on_publisher' => MarketingOpsQueues::sitesWaitingOnPublisher()->count(),
            'bulk_waiting_on_publisher' => MarketingOpsQueues::bulkWaitingOnPublisher()->count(),
            'my_tasks_today' => $this->marketerHistoryQuery($userId)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->count(),
            'my_tasks_total' => $this->marketerHistoryQuery($userId)->count(),
        ];

        $readySites = MarketingOpsQueues::sitesReadyForStaff()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(8)
            ->get();

        $waitingSites = MarketingOpsQueues::sitesWaitingOnPublisher()
            ->with('publisher:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(5)
            ->get();

        $openBulk = MarketingOpsQueues::bulkWaitingOnMarketer()
            ->with([
                'publisher:id,name,email',
                'handler:id,name',
            ])
            ->withCount([
                'items as pending_items_count' => fn ($q) => $q->whereNull('site_id'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->take(5)
            ->get();

        $recentHistory = $this->marketerHistoryQuery($userId)
            ->latest('id')
            ->take(12)
            ->get();

        $historyToday = ActivityLogDateBounds::todayDateString();

        return view('marketing.dashboard', compact(
            'stats',
            'readySites',
            'waitingSites',
            'openBulk',
            'recentHistory',
            'historyToday'
        ));
    }

    public function queueCounts()
    {
        return response()->json([
            'success' => true,
            'ready_sites' => MarketingOpsQueues::sitesReadyForStaffCount(),
            'bulk_waiting' => MarketingOpsQueues::bulkWaitingOnMarketerCount(),
        ]);
    }

    public function history(Request $request)
    {
        $userId = (int) auth()->id();
        $query = $this->marketerHistoryQuery($userId);

        $selectedAction = search_text($request->input('action'));
        if ($selectedAction !== '' && ! in_array($selectedAction, self::TRACKED_ACTIONS, true)) {
            $selectedAction = '';
        }

        $dateErrors = ActivityLogDateBounds::apply(
            $query,
            $request->input('from'),
            $request->input('to')
        );

        $searchNeedle = search_text($request->input('q'));
        if ($searchNeedle !== '') {
            $matchedActions = marketing_task_actions_matching($searchNeedle);
            $like = like_contains($searchNeedle);
            $query->where(function ($q) use ($searchNeedle, $matchedActions, $like) {
                $q->whereRaw('subject_label LIKE ? ESCAPE ?', [$like, '\\']);
                $q->orWhere(function ($inner) use ($searchNeedle) {
                    ActivityLogTextSearch::whereDescriptionHasWord($inner, $searchNeedle);
                });
                if ($matchedActions !== []) {
                    $q->orWhereIn('action', $matchedActions);
                }
            });
        }

        $actionCounts = (clone $query)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        if ($selectedAction !== '') {
            $query->where('action', $selectedAction);
        }

        $logs = $query->latest('id')->paginate(30)->withQueryString();

        if ($request->integer('page') > 1 && $logs->total() > 0 && $logs->count() === 0) {
            return redirect()->to($logs->url(max(1, $logs->lastPage())));
        }

        $actions = self::TRACKED_ACTIONS;

        $filtersActive = $searchNeedle !== ''
            || $selectedAction !== ''
            || $request->filled('from')
            || $request->filled('to');

        return view('marketing.history', compact(
            'logs',
            'actions',
            'actionCounts',
            'selectedAction',
            'dateErrors',
            'filtersActive'
        ));
    }

    /**
     * @return Builder<ActivityLog>
     */
    private function marketerHistoryQuery(int $userId)
    {
        return ActivityLog::query()
            ->where('user_id', $userId)
            ->where('role', 'marketing')
            ->whereIn('action', self::TRACKED_ACTIONS);
    }
}
