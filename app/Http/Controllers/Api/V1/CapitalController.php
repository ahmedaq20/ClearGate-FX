<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Capital\CapitalMovementRequest;
use App\Http\Requests\Capital\CapitalReportRequest;
use App\Http\Requests\Capital\TransferCapitalToBoxRequest;
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
