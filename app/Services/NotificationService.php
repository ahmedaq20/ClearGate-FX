<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
    /**
     * @param  int|array<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function send(int|array $userIds, string $type, string $title, ?string $body = null, array $data = []): void
    {
        foreach (array_unique((array) $userIds) as $userId) {
            Notification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

            $this->forgetUnreadCount((int) $userId);
        }
    }

    public function markAsRead(int $notificationId, int $userId): Notification
    {
        $notification = Notification::query()
            ->whereKey($notificationId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        $this->forgetUnreadCount($userId);

        return $notification->refresh();
    }

    public function markAllAsRead(int $userId): void
    {
        Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        $this->forgetUnreadCount($userId);
    }

    public function getUnreadCount(int $userId): int
    {
        return (int) Cache::remember(
            $this->unreadCountCacheKey($userId),
            300,
            fn (): int => Notification::query()
                ->where('user_id', $userId)
                ->where('is_read', false)
                ->count()
        );
    }

    public function forgetUnreadCount(int $userId): void
    {
        Cache::forget($this->unreadCountCacheKey($userId));
    }

    private function unreadCountCacheKey(int $userId): string
    {
        return "notif.unread.{$userId}";
    }
}
