<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Expense\StoreOwnerExpenseRequest;
use App\Http\Requests\Expense\UpdateOwnerExpenseRequest;
use App\Models\OwnerExpense;
use App\Services\CapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerExpenseController extends BaseApiController
{
    public function __construct(
        private CapitalService $capitalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(
            $this->currentUser($request)
                ->ownerExpenses()
                ->latest('expense_date')
                ->latest('id')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreOwnerExpenseRequest $request): JsonResponse
    {
        $expense = $this->capitalService->createExpense($this->currentUser($request), $request->validated());

        return $this->sendResponse($expense, 'تم إنشاء مصروف المالك', 201);
    }

    public function update(UpdateOwnerExpenseRequest $request, OwnerExpense $expense): JsonResponse
    {
        $expense = $this->capitalService->updateExpense($this->currentUser($request), $expense, $request->validated());

        return $this->sendResponse($expense, 'تم تحديث مصروف المالك');
    }

    public function destroy(Request $request, OwnerExpense $expense): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $this->capitalService->deleteExpense($this->currentUser($request), $expense);

        return $this->sendResponse(null, 'تم حذف مصروف المالك');
    }
}
