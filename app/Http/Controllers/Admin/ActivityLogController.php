<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ActivityLogDateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * Admin-only append-only history of actions that call ActivityLogger.
     * Marketers use /marketing/history.
     */
    public const EXPORT_LIMIT = 10000;

    /** @var list<string> */
    public const ROLES = ['admin', 'marketing', 'publisher', 'advertiser'];

    public function index(Request $request)
    {
        [$query, $meta] = $this->filteredQuery($request);

        $actionCounts = (clone $query)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        if ($meta['selectedAction'] !== '') {
            $query->where('action', $meta['selectedAction']);
        }

        $logs = $query->latest('id')->paginate(25)->withQueryString();

        if ($request->integer('page') > 1 && $logs->total() > 0 && $logs->count() === 0) {
            return redirect()->to($logs->url(max(1, $logs->lastPage())));
        }

        $actions = array_keys(activity_action_labels());
        foreach ($actionCounts->keys() as $code) {
            if (is_string($code) && $code !== '' && ! in_array($code, $actions, true)) {
                $actions[] = $code;
            }
        }
        sort($actions);

        return view('admin.activity-logs', array_merge($meta, compact(
            'logs',
            'actions',
            'actionCounts'
        )));
    }

    public function export(Request $request): StreamedResponse
    {
        [$query, $meta] = $this->filteredQuery($request);

        if ($meta['selectedAction'] !== '') {
            $query->where('action', $meta['selectedAction']);
        }

        $rows = $query->latest('id')->limit(self::EXPORT_LIMIT)->get();
        $filename = 'activity-logs-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'when',
                'user_name',
                'user_email',
                'role',
                'action',
                'subject',
                'description',
                'properties',
                'ip_address',
            ]);

            foreach ($rows as $log) {
                fputcsv($out, [
                    $this->csvCell(optional($log->created_at)?->toIso8601String()),
                    $this->csvCell($log->user_name),
                    $this->csvCell($log->user_email),
                    $this->csvCell($log->role),
                    $this->csvCell($log->action),
                    $this->csvCell($log->subject_label),
                    $this->csvCell($log->description),
                    $this->csvCell(is_array($log->properties) ? json_encode($log->properties) : $log->properties),
                    $this->csvCell($log->ip_address),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: Builder<ActivityLog>, 1: array{
     *     dateErrors: list<string>,
     *     filtersActive: bool,
     *     selectedAction: string,
     *     selectedRole: string
     * }}
     */
    private function filteredQuery(Request $request): array
    {
        $query = ActivityLog::query();

        $dateErrors = ActivityLogDateBounds::apply(
            $query,
            $request->input('from'),
            $request->input('to')
        );

        $term = search_text($request->input('user'));
        $userId = (int) $request->input('user_id');
        if ($term !== '') {
            $like = like_contains($term);
            $query->where(function ($q) use ($like, $term) {
                $q->whereRaw('user_name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('user_email LIKE ? ESCAPE ?', [$like, '\\']);
                if (ctype_digit($term) && (string) ((int) $term) === $term) {
                    $q->orWhere('user_id', (int) $term);
                }
            });
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        $selectedRole = search_text($request->input('role'));
        if (! in_array($selectedRole, self::ROLES, true)) {
            $selectedRole = '';
        }
        if ($selectedRole !== '') {
            $query->where('role', $selectedRole);
        }

        $needle = search_text($request->input('q'));
        if ($needle !== '') {
            $like = like_contains($needle);
            $matchedActions = activity_action_actions_matching($needle);
            $query->where(function ($q) use ($like, $matchedActions) {
                $q->whereRaw('subject_label LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('description LIKE ? ESCAPE ?', [$like, '\\']);
                if ($matchedActions !== []) {
                    $q->orWhereIn('action', $matchedActions);
                }
            });
        }

        $selectedAction = search_text($request->input('action'));
        if ($selectedAction !== '' && ! preg_match('/^[a-z0-9_.]+$/', $selectedAction)) {
            $selectedAction = '';
        }

        $filtersActive = $term !== ''
            || $userId > 0
            || $selectedRole !== ''
            || $needle !== ''
            || $selectedAction !== ''
            || $request->filled('from')
            || $request->filled('to');

        return [
            $query,
            [
                'dateErrors' => $dateErrors,
                'filtersActive' => $filtersActive,
                'selectedAction' => $selectedAction,
                'selectedRole' => $selectedRole,
            ],
        ];
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
