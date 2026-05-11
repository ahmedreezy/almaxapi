<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StatusCheck;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/notifications/status-check    — public: log a user status check
 * GET  /api/notifications                  — admin: list last 50
 * GET  /api/notifications/unread-count     — admin: badge count
 * PATCH /api/notifications/:id/read        — admin: mark one as read
 * PATCH /api/notifications/read-all        — admin: mark all as read
 */
class NotificationController extends Controller
{
    /** Public: log when a user checks their subscription status */
    public function statusCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId'    => ['sometimes', 'nullable', 'integer'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'username'  => ['sometimes', 'nullable', 'string', 'max:200'],
            'planType'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'subStatus' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        StatusCheck::create([
            'user_id'    => $data['userId'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'username'   => $data['username'] ?? null,
            'plan_type'  => $data['planType'] ?? null,
            'sub_status' => $data['subStatus'] ?? null,
            'is_read'    => false,
        ]);

        return response()->json(['logged' => true]);
    }

    /** Admin: last 50 status checks with user info */
    public function index(): JsonResponse
    {
        $checks = StatusCheck::with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json($checks);
    }

    /** Admin: count of unread status checks */
    public function unreadCount(): JsonResponse
    {
        $count = StatusCheck::where('is_read', false)->count();

        return response()->json(['count' => $count]);
    }

    /** Admin: mark one notification as read */
    public function markRead(int $id): JsonResponse
    {
        StatusCheck::findOrFail($id)->update(['is_read' => true]);

        return response()->json(['updated' => true]);
    }

    /** Admin: mark all notifications as read */
    public function markAllRead(): JsonResponse
    {
        StatusCheck::where('is_read', false)->update(['is_read' => true]);

        return response()->json(['updated' => true]);
    }
}
