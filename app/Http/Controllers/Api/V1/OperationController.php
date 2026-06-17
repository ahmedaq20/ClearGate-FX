<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OperationStatus;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Operation\CancelOperationRequest;
use App\Http\Requests\Operation\StoreOperationRequest;
use App\Http\Requests\Operation\UpdateOperationRequest;
use App\Http\Resources\OperationResource;
use App\Models\Operation;
use App\Services\OperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Operations
 *
 * Manage complete money transfer operations beside the legacy transaction system.
 */
class OperationController extends BaseApiController
{
    public function __construct(
        private OperationService $operationService,
    ) {}

    /**
     * List operations
     *Continue following AGENTS.md and the updated project plan.

Phase 5: Operations Dashboard & Financial Monitoring

Business Goal:

Management needs a real-time dashboard to monitor:

     * liquidity
     * pending supplier operations
     * completed operations
     * commissions
     * suppliers
     * boxes

---

## DASHBOARD SUMMARY

Create a financial dashboard endpoint.

GET /api/v1/dashboard/financial

Return:

{
"total_boxes_balance": 0,

"pending_operations_count": 0,
"pending_operations_amount": 0,

"completed_operations_count": 0,
"completed_operations_amount": 0,

"today_operations_count": 0,
"today_operations_amount": 0,

"today_commissions": 0,

"suppliers_count": 0,
"customers_count": 0,

"boxes_count": 0
}

---

## PENDING OPERATIONS WIDGET

Return:

Top pending operations:

     * reference number
     * supplier
     * customer
     * amount
     * transaction date
     * pending days

Example:

[
{
"reference_number": "TRX-2026-00025",
"supplier": "محمد",
"customer": "أحمد",
"amount": 1000,
"pending_days": 3
}
]

---

## SUPPLIER MONITORING

New endpoint:

GET /api/v1/dashboard/suppliers

Return:

     * total suppliers
     * suppliers with pending operations
     * top suppliers by volume
     * top suppliers by commission

---

## BOX MONITORING

New endpoint:

GET /api/v1/dashboard/boxes

Return:

     * box name
     * box type
     * current balance
     * last activity date

---

## COMMISSION ANALYTICS

New endpoint:

GET /api/v1/dashboard/commissions

Return:

     * today commissions
     * monthly commissions
     * yearly commissions

---

## CHART DATA

New endpoint:

GET /api/v1/dashboard/charts

Return:

     * operations by day
     * commissions by day
     * pending vs completed

Return chart-ready JSON.

---

## FILTER SUPPORT

Allow filters:

     * date_from
     * date_to
     * supplier_id
     * box_id

---

## PERMISSIONS

Owner:

     * full dashboard access

Manager:

     * according to assigned permissions

---

## IMPORTANT

Do NOT create frontend.

Provide API-only responses.

Do NOT modify operations logic.

Do NOT modify box balances.

Do NOT modify customer logic.

---

## AFTER FINISHING

     * Run vendor/bin/pint --dirty --format agent
     * Run php artisan test --compact
     * Run PHP syntax checks
     * Show changed files
     * Summarize new dashboard endpoints
     * Show example responses

     *
     * @authenticated
     *
     * @queryParam customer integer Filter by customer ID. Example: 12
     * @queryParam supplier integer Filter by supplier ID. Example: 7
     * @queryParam box integer Filter by box ID. Example: 3
     * @queryParam status string Filter by operation status: pending, completed, or cancelled. Example: pending
     * @queryParam date_from date Filter from transaction date. Example: 2026-06-01
     * @queryParam date_to date Filter to transaction date. Example: 2026-06-30
     * @queryParam reference_number string Filter by reference number. Example: TRX-2026-00001
     * @queryParam per_page integer Results per page. Example: 20
     */
    public function index(Request $request): JsonResponse
    {
        $query = Operation::query()
            ->with(['customer', 'supplier', 'box', 'creator'])
            ->latest('transaction_date')
            ->latest('id');
        $query = $this->scopeToCurrentUser($query, $request, 'created_by');

        $query
            ->when($request->filled('customer'), fn (Builder $query) => $query->where('customer_id', $request->integer('customer')))
            ->when($request->filled('supplier'), fn (Builder $query) => $query->where('supplier_id', $request->integer('supplier')))
            ->when($request->filled('box'), fn (Builder $query) => $query->where('box_id', $request->integer('box')))
            ->when($this->validStatus($request), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('transaction_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('transaction_date', '<=', $request->date('date_to')))
            ->when($request->filled('reference_number'), fn (Builder $query) => $query->where('reference_number', $request->string('reference_number')));

        return $this->sendResponse(OperationResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    /**
     * Create operation
     *
     * Supplier-funded operations must include status as pending or completed. Box-funded operations omit status and are stored as completed automatically.
     *
     * @authenticated
     *
     * @bodyParam status string required Required for supplier-funded operations. Allowed values: pending, completed. Box-funded operations are always stored as completed even when omitted. Example: pending
     * @bodyParam supplier_id integer Supplier ID for supplier-funded operations. Example: 5
     * @bodyParam box_id integer Box ID for box-funded operations. Example: 3
     * @bodyParam customer_id integer required Receiving customer ID. Example: 10
     *
     * @response 201 {"success":true,"message":"تم إنشاء العملية","data":{"supplier_id":5,"box_id":null,"customer_id":10,"status":"pending"}}
     * @response 201 {"success":true,"message":"تم إنشاء العملية","data":{"supplier_id":5,"box_id":null,"customer_id":10,"status":"completed"}}
     * @response 201 {"success":true,"message":"تم إنشاء العملية","data":{"supplier_id":null,"box_id":3,"customer_id":10,"status":"completed"}}
     */
    public function store(StoreOperationRequest $request): JsonResponse
    {
        $operation = $this->operationService->store($request->validated(), $this->currentUser($request));

        return $this->sendResponse(
            OperationResource::make($operation->load(['customer', 'supplier', 'box', 'creator'])),
            'تم إنشاء العملية',
            201
        );
    }

    /**
     * Show operation
     *
     * @authenticated
     */
    public function show(Request $request, Operation $operation): JsonResponse
    {
        if (! $this->canAccessOperation($request, $operation)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(OperationResource::make($operation->load(['customer', 'supplier', 'box', 'creator'])));
    }

    /**
     * Update operation
     *
     * @authenticated
     */
    public function update(UpdateOperationRequest $request, Operation $operation): JsonResponse
    {
        if (! $this->canAccessOperation($request, $operation)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $operation = $this->operationService->update($operation, $request->validated(), $this->currentUser($request));

        return $this->sendResponse(
            OperationResource::make($operation->load(['customer', 'supplier', 'box', 'creator'])),
            'تم تحديث العملية'
        );
    }

    /**
     * Delete operation
     *
     * @authenticated
     */
    public function destroy(Request $request, Operation $operation): JsonResponse
    {
        if (! $this->canAccessOperation($request, $operation)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $this->operationService->delete($operation, $this->currentUser($request));

        return $this->sendResponse(null, 'تم حذف العملية');
    }

    public function pending(Request $request): JsonResponse
    {
        return $this->listByStatus($request, OperationStatus::Pending);
    }

    public function completed(Request $request): JsonResponse
    {
        return $this->listByStatus($request, OperationStatus::Completed);
    }

    public function cancelled(Request $request): JsonResponse
    {
        return $this->listByStatus($request, OperationStatus::Cancelled);
    }

    public function complete(Request $request, int $operation): JsonResponse
    {
        $operationModel = Operation::query()->find($operation);

        if ($operationModel === null) {
            return $this->sendError('العملية غير موجودة', [], 404);
        }

        if (! $this->canAccessOperation($request, $operationModel)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if ($error = $this->statusError($operationModel)) {
            return $error;
        }

        try {
            $this->operationService->complete($operationModel, $this->currentUser($request));
        } catch (ValidationException $exception) {
            return $this->sendError($this->firstValidationMessage($exception), $exception->errors(), 422);
        }

        return $this->sendResponse(null, 'تم استكمال العملية بنجاح');
    }

    public function cancel(CancelOperationRequest $request, int $operation): JsonResponse
    {
        $operationModel = Operation::query()->find($operation);

        if ($operationModel === null) {
            return $this->sendError('العملية غير موجودة', [], 404);
        }

        if (! $this->canAccessOperation($request, $operationModel)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        if ($error = $this->statusError($operationModel, allowPending: true)) {
            return $error;
        }

        try {
            $this->operationService->cancel(
                $operationModel,
                $this->currentUser($request),
                (string) $request->validated('cancellation_reason')
            );
        } catch (ValidationException $exception) {
            return $this->sendError($this->firstValidationMessage($exception), $exception->errors(), 422);
        }

        return $this->sendResponse(null, 'تم إلغاء العملية بنجاح');
    }

    /**
     * Operation receipt
     *
     * @authenticated
     */
    public function receipt(Request $request, Operation $operation): JsonResponse
    {
        if (! $this->canAccessOperation($request, $operation)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse([
            'operation' => OperationResource::make($operation->load(['customer', 'supplier', 'box', 'creator'])),
            'generated_at' => now(),
        ]);
    }

    private function canAccessOperation(Request $request, Operation $operation): bool
    {
        return $this->isOwner($request->user()) || $operation->created_by === $request->user()?->id;
    }

    private function listByStatus(Request $request, OperationStatus $status): JsonResponse
    {
        $query = Operation::query()
            ->with(['customer', 'supplier', 'box', 'creator'])
            ->where('status', $status->value)
            ->latest('transaction_date')
            ->latest('id');
        $query = $this->scopeToCurrentUser($query, $request, 'created_by');

        return $this->sendResponse(OperationResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    private function validStatus(Request $request): ?string
    {
        $status = $request->string('status')->toString();
        $allowedStatuses = array_column(OperationStatus::cases(), 'value');

        return in_array($status, $allowedStatuses, true) ? $status : null;
    }

    private function statusError(Operation $operation, bool $allowPending = false): ?JsonResponse
    {
        if ($operation->status === OperationStatus::Completed) {
            return $this->sendError('العملية مكتملة مسبقاً', [], 422);
        }

        if ($operation->status === OperationStatus::Cancelled) {
            return $this->sendError('العملية ملغاة', [], 422);
        }

        if (! $allowPending && $operation->status !== OperationStatus::Pending) {
            return $this->sendError('العملية مكتملة مسبقاً', [], 422);
        }

        return null;
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        $messages = collect($exception->errors())->flatten();

        return (string) ($messages->first() ?? 'Validation Error');
    }
}
