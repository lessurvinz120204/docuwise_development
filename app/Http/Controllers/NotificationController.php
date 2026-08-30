<?php

namespace App\Http\Controllers;

use App\Models\NotificationRecord;
use Illuminate\Http\Request;

/**
 * NotificationController
 * -----------------------
 * Section 3: In-app Notification Center. NotificationRecord::send() has
 * always written rows across the app (submission alerts, decision
 * alerts, SLA escalations, etc.) but nothing previously read them back —
 * this exposes that history to whichever role the recipient is.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Real page route, not the implicit current-request path — this
        // list is also built from within listRefresh() (the live-poll
        // fragment route); see AdminController::paginateContainers()'s
        // docblock for the full reasoning.
        $notifications = $request->user()->notifications()->with('document')->paginate(10)
            ->withPath(route('notifications.index'));

        return view('notifications.index', compact('notifications'));
    }

    /**
     * The full Notifications page's list fragment (notifications/partials/
     * list.blade.php) — fetched live whenever a new notification arrives
     * for this user (see index.blade.php's script) so the page updates
     * without a manual reload, distinct from refresh() below which only
     * ever serves the bell dropdown's smaller unread-only preview.
     */
    public function listRefresh(Request $request)
    {
        $notifications = $request->user()->notifications()->with('document')->paginate(10)
            ->withPath(route('notifications.index'));

        return view('notifications.partials.list', compact('notifications'));
    }

    /**
     * Lightweight JSON endpoint the notification bell polls every ~5-10s
     * (see startLivePoll() in resources/js/app.js, wired up in the bell's
     * own markup since it appears on every page, not just one dashboard).
     */
    public function poll(Request $request)
    {
        return response()->json([
            'unread_count' => $request->user()->notifications()->where('is_read', false)->count(),
        ]);
    }

    /**
     * Renders just the bell's inner content (notifications/partials/bell.blade.php)
     * for the live-poll JS to swap in place — see components/notification-bell.blade.php's
     * docblock for why only the <details> tag's children are swapped, never
     * the tag itself (preserves open/closed state across the swap).
     */
    public function refresh(Request $request)
    {
        $unread = $request->user()->notifications()->where('is_read', false)->limit(6)->get();
        $unreadCount = $request->user()->notifications()->where('is_read', false)->count();

        return view('notifications.partials.bell', compact('unread', 'unreadCount'));
    }

    public function markRead(Request $request, NotificationRecord $notification)
    {
        $this->authorize('markRead', $notification);

        $notification->update(['is_read' => true]);

        return back();
    }

    public function markAllRead(Request $request)
    {
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back();
    }
}
