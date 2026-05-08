<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SettingsService;

class CheckLargeTransactionListener
{
    public function __construct(
        private NotificationService $notificationService,
        private SettingsService $settingsService,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(TransactionCreated $event): void
    {
        $threshold = (float) $this->settingsService->get('large_transaction_threshold', 10000);
        $usdValue = (float) $event->transaction->usd_value;

        if ($usdValue < $threshold) {
            return;
        }

        $ownerIds = User::role('owner', 'sanctum')->pluck('id')->all();

        if ($ownerIds === []) {
            return;
        }

        $this->notificationService->send(
            $ownerIds,
            'transaction.large',
            'عملية كبيرة',
            "تم تسجيل عملية بقيمة {$usdValue} دولار.",
            ['transaction_id' => $event->transaction->id]
        );
    }
}
