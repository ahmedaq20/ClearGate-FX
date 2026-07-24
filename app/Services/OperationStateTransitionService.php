<?php

namespace App\Services;

use App\Enums\OperationCustomerSettlementStatus;
use App\Enums\OperationStatus;
use App\Enums\OperationSupplierFulfillmentStatus;
use App\Models\Operation;

class OperationStateTransitionService
{
    public function sync(Operation $operation): Operation
    {
        if ($operation->status === OperationStatus::Cancelled) {
            return $operation;
        }

        if ($operation->status === OperationStatus::Completed) {
            return $operation;
        }

        if (! $this->isCommerciallyComplete($operation)) {
            return $operation;
        }

        $operation->update([
            'status' => OperationStatus::Completed->value,
            'completed_at' => $operation->completed_at ?? now(),
        ]);

        return $operation->refresh();
    }

    private function isCommerciallyComplete(Operation $operation): bool
    {
        return $operation->customer_settlement_status === OperationCustomerSettlementStatus::Completed
            && $this->supplierExecutionComplete($operation);
    }

    private function supplierExecutionComplete(Operation $operation): bool
    {
        if ($operation->supplier_id === null) {
            return true;
        }

        return $operation->supplier_fulfillment_status === OperationSupplierFulfillmentStatus::Completed;
    }
}
