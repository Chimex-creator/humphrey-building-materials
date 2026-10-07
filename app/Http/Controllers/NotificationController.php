<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** PHASE 8 — in-app notification centre (the 🔔 bell). */
class NotificationController extends Controller
{
    /** List the logged-in user's notifications (newest first). */
    public function index(Request $request)
    {
        $notifications = auth()->user()
            ->notifications()
            ->paginate(15);

        return view('notifications.index', compact('notifications'));
    }

    /** Mark one notification as read (only the owner's own). */
    public function read(DatabaseNotification $notification)
    {
        $this->authorizeNotification($notification);

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        // Jump to the page the notification points at, when it has a URL.
        $url = $notification->data['url'] ?? null;

        if ($url && str_starts_with($url, url('/'))) {
            return redirect($url);
        }

        return redirect()->route('notifications.index')->with('status', 'Notification marked as read.');
    }

    /** Mark everything as read. */
    public function readAll(Request $request)
    {
        auth()->user()
            ->unreadNotifications()
            ->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'All notifications marked as read.');
    }

    /**
     * Final Spec §39 — bulk "mark selected as read".
     * Only the owner's own notifications can ever be touched; foreign ids
     * are filtered out before anything is updated.
     */
    public function readSelected(Request $request)
    {
        $data = $request->validate([
            'notification_ids' => ['required', 'array', 'min:1'],
            'notification_ids.*' => ['string', 'max:40'],
        ]);

        $count = 0;
        foreach ($data['notification_ids'] as $id) {
            $notification = auth()->user()->notifications()->whereKey($id)->first();
            if ($notification && ! $notification->read_at) {
                $notification->markAsRead();
                $count++;
            }
        }

        return redirect()->route('notifications.index')->with(
            'status',
            $count > 0
                ? $count.' notification(s) marked as read.'
                : 'Those notifications were already read.'
        );
    }

    /** 403 unless the notification belongs to the logged-in user. */
    protected function authorizeNotification(DatabaseNotification $notification): void
    {
        if ($notification->notifiable_type !== User::class
            || (int) $notification->notifiable_id !== (int) auth()->id()) {
            abort(403, 'This notification does not belong to you.');
        }
    }
}
