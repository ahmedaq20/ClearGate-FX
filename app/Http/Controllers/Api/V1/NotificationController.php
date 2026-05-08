<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Notifications
 *
 * Read and manage in-app notifications for the authenticated user.
 */
class NotificationController extends BaseApiController
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * List notifications
     *
     * Return paginated notifications for the authenticated user.
     *
     * @authenticated
     *
     * @queryParam is_read boolean Filter by read status. Example: false
     * @queryParam type string Filter by notification type. Example: report_ready
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"type":"system","title":"Notification title","body":"Notification body","is_read":false}]}
     */
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

    /**
     * Unread notification count
     *
     * Return the number of unread notifications for the authenticated user.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"count":3}}
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->sendResponse([
            'count' => $this->notificationService->getUnreadCount((int) $request->user()?->id),
        ]);
    }

    /**
     * Mark notification as read
     *
     * Mark one notification as read if it belongs to the authenticated user.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تعليم الإشعار كمقروء","data":{"id":1,"is_read":true}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $this->notificationService->markAsRead($notification->id, (int) $request->user()?->id),
            'تم تعليم الإشعار كمقروء'
        );
    }

    /**
     * Mark all notifications as read
     *
     * Mark every unread notification for the authenticated user as read.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تعليم كل الإشعارات كمقروءة"}
     */
    public function readAll(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead((int) $request->user()?->id);

        return $this->sendResponse(null, 'تم تعليم كل الإشعارات كمقروءة');
    }

    /**
     * Delete notification
     *
     * Delete one notification if it belongs to the authenticated user.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم حذف الإشعار"}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $notification->delete();
        $this->notificationService->forgetUnreadCount((int) $request->user()?->id);

        return $this->sendResponse(null, 'تم حذف الإشعار');
    }
}
