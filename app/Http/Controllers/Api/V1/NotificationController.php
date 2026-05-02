<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->where('user_id', $request->user()?->id)
            ->latest();

        $query
            ->when($request->filled('is_read'), fn ($query) => $query->where('is_read', $request->boolean('is_read')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')));

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->sendResponse([
            'count' => Notification::query()
                ->where('user_id', $request->user()?->id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $this->sendResponse($notification->refresh(), 'تم تعليم الإشعار كمقروء');
    }

    public function readAll(Request $request): JsonResponse
    {
        Notification::query()
            ->where('user_id', $request->user()?->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->sendResponse(null, 'تم تعليم كل الإشعارات كمقروءة');
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $notification->delete();

        return $this->sendResponse(null, 'تم حذف الإشعار');
    }
}
