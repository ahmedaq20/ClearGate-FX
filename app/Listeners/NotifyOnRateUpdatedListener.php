<?php

namespace App\Listeners;

use App\Events\ExchangeRateUpdated;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\NotificationService;

class NotifyOnRateUpdatedListener
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(ExchangeRateUpdated $event): void
    {
        $exchangeRate = $event->exchangeRate;

        AuditLog::record(
            action: 'exchange_rate.updated',
            model: $exchangeRate,
            userId: $event->actor->id,
            oldValues: ['rate' => $event->oldRate],
            newValues: [
                'currency_code' => $exchangeRate->currency_code,
                'rate' => (float) $exchangeRate->rate,
                'date' => $exchangeRate->date?->toDateString(),
            ]
        );

        $userIds = User::role(['owner', 'manager'], 'sanctum')->pluck('id')->all();

        $this->notificationService->send(
            $userIds,
            'rate.updated',
            'تحديث سعر الصرف',
            "تم تحديث سعر {$exchangeRate->currency_code} إلى {$exchangeRate->rate}.",
            [
                'currency_code' => $exchangeRate->currency_code,
                'rate' => (float) $exchangeRate->rate,
                'exchange_rate_id' => $exchangeRate->id,
            ]
        );
    }
}
