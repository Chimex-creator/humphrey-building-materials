<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

/**
 * PHASE 12 — read-only audit trail (Master Prompt §47).
 *
 * Admin-only: the full record of who did what in the business.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $actionFilter = $request->input('action');

        $query = ActivityLog::query()->with('user')->latest('id');

        if ($search) {
            // `details` is a JSON blob; casting it to a string keeps the LIKE
            // portable across MySQL (json) and SQLite (text) in the test suite.
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($like) {
                $q->where('description', 'like', $like)
                    ->orWhere('subject_label', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhereRaw('CAST(details AS CHAR) LIKE ?', [$like])
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
            });
        }

        if ($actionFilter && array_key_exists($actionFilter, ActivityLog::ACTIONS)) {
            $query->where('action', $actionFilter);
        }

        $logs = $query->paginate(25)->withQueryString();

        $summary = [
            'total' => ActivityLog::count(),
            'today' => ActivityLog::whereDate('created_at', today())->count(),
            'actors' => ActivityLog::whereNotNull('user_id')->distinct()->count('user_id'),
        ];

        return view('admin.activity-logs.index', compact('logs', 'search', 'actionFilter', 'summary'));
    }
}
