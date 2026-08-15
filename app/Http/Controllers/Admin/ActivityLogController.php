<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Activity history for the admin panel.
     * Visible to admin + marketing (read-only).
     */
    public function index(Request $request)
    {
        $query = ActivityLog::query()->latest();

        $action = search_text($request->input('action'));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $term = search_text($request->input('user'));
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('user_name', 'like', '%'.$term.'%')
                    ->orWhere('user_email', 'like', '%'.$term.'%');
            });
        }

        $from = search_text($request->input('from'));
        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = search_text($request->input('to'));
        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate(25)->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('admin.activity-logs', compact('logs', 'actions'));
    }
}
