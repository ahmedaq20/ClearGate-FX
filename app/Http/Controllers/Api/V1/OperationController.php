<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Operation\StoreOperationRequest;
use App\Http\Requests\Operation\UpdateOperationRequest;
use App\Http\Resources\OperationResource;
use App\Models\Operation;
use App\Services\OperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     *
     * @authenticated
     *
     * @queryParam customer integer Filter by customer ID. Example: 12
     * @queryParam supplier integer Filter by supplier ID. Example: 7
     * @queryParam box integer Filter by box ID. Example: 3
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
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('transaction_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('transaction_date', '<=', $request->date('date_to')))
            ->when($request->filled('reference_number'), fn (Builder $query) => $query->where('reference_number', $request->string('reference_number')));

        return $this->sendResponse(OperationResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    /**
     * Create operation
     *
     * @authenticated
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
}
