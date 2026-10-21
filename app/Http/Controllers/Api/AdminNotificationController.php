<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $query = $user->adminNotifications()->latest();

        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->where('category', $request->input('category'));
        }

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $notifications = $query->paginate($perPage);
        $unreadCount = $user->adminNotifications()->unread()->count();

        return response()->json([
            'data' => $notifications->items(),
            'current_page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
            'total' => $notifications->total(),
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Lightweight unread counter for polling the header bell badge.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['unread_count' => 0], 401);
        }

        $unreadCount = $user->adminNotifications()->unread()->count();

        return response()->json([
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->adminNotifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read',
            'unread_count' => $user->adminNotifications()->unread()->count(),
        ]);
    }

    /**
     * Mark all unread notifications for the user as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->adminNotifications()->unread()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'unread_count' => 0,
        ]);
    }

    /**
     * Dismiss / delete a notification.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->adminNotifications()->findOrFail($id);
        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully',
            'unread_count' => $user->adminNotifications()->unread()->count(),
        ]);
    }
}
