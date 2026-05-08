<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionRestored;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ArchiveService;
use App\Services\NotificationService;

class LogTransactionAuditListener
{
    public function __construct(
        private ArchiveService $archiveService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(TransactionCreated|TransactionDeleted|TransactionRestored $event): void
    {
        $transaction = $event->transaction;

        $action = match ($event::class) {
            TransactionCreated::class => 'transaction.created',
            TransactionDeleted::class => 'transaction.deleted',
            TransactionRestored::class => 'transaction.restored',
        };

        AuditLog::record(
            action: $action,
            model: $transaction,
            userId: $event->actor->id,
            oldValues: $event instanceof TransactionCreated ? null : $transaction->attributesToArray(),
            newValues: $event instanceof TransactionDeleted ? null : $transaction->attributesToArray()
        );

        if ($event instanceof TransactionDeleted) {
            $this->archiveService->archive($transaction, $event->actor, 'transaction.deleted', $transaction->attributesToArray());
        }

        if ($event instanceof TransactionRestored) {
            $ownerIds = User::role('owner', 'sanctum')->pluck('id')->all();

            $this->notificationService->send(
                $ownerIds,
                'transaction.restored',
                'استعادة عملية',
                "تمت استعادة العملية رقم {$transaction->id}.",
                ['transaction_id' => $transaction->id]
            );
        }
    }
}
