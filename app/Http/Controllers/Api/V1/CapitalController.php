<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Capital\CapitalAccountMovementRequest;
use App\Http\Requests\Capital\CapitalAccountStoreRequest;
use App\Http\Requests\Capital\CapitalMovementRequest;
use App\Http\Requests\Capital\CapitalMovementUpdateRequest;
use App\Http\Requests\Capital\CapitalReportRequest;
use App\Http\Requests\Capital\TransferCapitalToBoxRequest;
use App\Models\CapitalAccount;
use App\Models\CapitalTransaction;
use App\Services\CapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapitalController extends BaseApiController
{
    public function __construct(
        private CapitalService $capitalService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(array_merge([
            'account' => $this->capitalService->account($this->currentUser($request)),
        ], $this->capitalService->dashboard($this->currentUser($request))));
    }

    public function deposit(CapitalMovementRequest $request): JsonResponse
    {
        $transaction = $this->capitalService->deposit($this->currentUser($request), $request->validated());

        return $this->sendResponse($transaction, 'تم إيداع رأس المال', 201);
    }

    public function withdraw(CapitalMovementRequest $request): JsonResponse
    {
        $transaction = $this->capitalService->withdraw($this->currentUser($request), $request->validated());

        return $this->sendResponse($transaction, 'تم سحب رأس المال', 201);
    }

    public function transferToBox(TransferCapitalToBoxRequest $request): JsonResponse
    {
        $transaction = $this->capitalService->transferToBox($this->currentUser($request), $request->validated());

        return $this->sendResponse($transaction, 'تم تحويل رأس المال إلى الصندوق', 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(
            $this->capitalService
                ->account($this->currentUser($request))
                ->transactions()
                ->with('box')
                ->latest('transaction_date')
                ->latest('id')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function accounts(Request $request): JsonResponse
    {
        return $this->sendResponse($this->capitalService->accountsOverview($this->currentUser($request)));
    }

    public function storeAccount(CapitalAccountStoreRequest $request): JsonResponse
    {
        $account = $this->capitalService->addCapitalToAccount($this->currentUser($request), $request->validated());

        return $this->sendResponse($account, 'تم حفظ حساب رأس المال', 201);
    }

    public function showAccount(Request $request, CapitalAccount $capitalAccount): JsonResponse
    {
        return $this->sendResponse($this->capitalService->accountDetails($this->currentUser($request), $capitalAccount, $request->only([
            'date_from',
            'date_to',
            'currency',
        ])));
    }

    public function storeAccountMovement(CapitalAccountMovementRequest $request, CapitalAccount $capitalAccount): JsonResponse
    {
        $transaction = $this->capitalService->createAccountMovement($this->currentUser($request), $capitalAccount, $request->validated());

        return $this->sendResponse($transaction->load('capitalAccount', 'creator'), 'تم حفظ حركة رأس المال', 201);
    }

    public function updateMovement(CapitalMovementUpdateRequest $request, CapitalTransaction $capitalTransaction): JsonResponse
    {
        $transaction = $this->capitalService->updateCapitalMovement($this->currentUser($request), $capitalTransaction, $request->validated());

        return $this->sendResponse($transaction, 'تم تحديث حركة رأس المال');
    }

    public function destroyMovement(Request $request, CapitalTransaction $capitalTransaction): JsonResponse
    {
        $this->capitalService->deleteCapitalMovement($this->currentUser($request), $capitalTransaction);

        return $this->sendResponse(message: 'تم حذف حركة رأس المال');
    }

    public function expenseReport(CapitalReportRequest $request): JsonResponse
    {
        return $this->sendResponse($this->capitalService->expenseReport($this->currentUser($request), $request->validated()));
    }

    public function capitalReport(CapitalReportRequest $request): JsonResponse
    {
        return $this->sendResponse($this->capitalService->capitalReport($this->currentUser($request), $request->validated()));
    }

    public function netWorthReport(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->capitalService->netWorthReport($this->currentUser($request)));
    }
}
