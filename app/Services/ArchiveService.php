<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ArchiveService
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public function archive(Model $model, User $actor, ?string $reason = null, ?array $snapshot = null): Archive
    {
        return Archive::query()->create([
            'archivable_type' => $this->archiveType($model),
            'archivable_id' => $model->getKey(),
            'archived_by' => $actor->id,
            'reason' => $reason,
            'snapshot' => $snapshot ?? $this->snapshot($model),
        ]);
    }

    public function restore(Archive $archive, User $actor): Model
    {
        return match ($archive->archivable_type) {
            'transaction' => app(TransactionService::class)->restore((int) $archive->archivable_id, $actor),
            'customer' => $this->restoreCustomer((int) $archive->archivable_id, $actor),
            default => throw ValidationException::withMessages([
                'archive' => 'نوع الأرشيف غير مدعوم.',
            ]),
        };
    }

    private function restoreCustomer(int $id, User $actor): Customer
    {
        $customer = Customer::withTrashed()->findOrFail($id);

        if (! $customer->trashed()) {
            throw ValidationException::withMessages([
                'customer' => 'لا يمكن استعادة عميل غير محذوف',
            ]);
        }

        $customer->restore();

        AuditLog::record(
            action: 'customer.restored',
            model: $customer,
            userId: $actor->id,
            newValues: $customer->attributesToArray()
        );

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        return $model->fresh()?->attributesToArray() ?? $model->attributesToArray();
    }

    private function archiveType(Model $model): string
    {
        return match (true) {
            $model instanceof Transaction => 'transaction',
            $model instanceof Customer => 'customer',
            default => throw ValidationException::withMessages([
                'archive' => 'نموذج الأرشيف غير مدعوم.',
            ]),
        };
    }
}
