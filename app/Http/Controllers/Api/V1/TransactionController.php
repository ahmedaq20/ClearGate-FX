<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends BaseApiController
{
    public function __construct(
        private TransactionService $transactionService,
        private BalanceService $balanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()->with(['user', 'vault', 'customer', 'currency'])->latest('transaction_date');
        $query = $this->scopeToCurrentUser($query, $request);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('transaction_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('transaction_date', '<=', $request->date('date_to')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('currency'), fn ($query) => $query->where('currency_code', $request->string('currency')))
            ->when($request->filled('vault_id'), fn ($query) => $query->where('vault_id', $request->integer('vault_id')))
            ->when($request->filled('country'), fn ($query) => $query->where('country', $request->string('country')))
            ->when($request->filled('min_usd'), fn ($query) => $query->where('net_usd_value', '>=', $request->float('min_usd')))
            ->when($request->filled('max_usd'), fn ($query) => $query->where('net_usd_value', '<=', $request->float('max_usd')));

        if ($this->isOwner($request->user()) && $request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->store($request->validated(), $this->currentUser($request));

        return $this->sendResponse($transaction->load(['user', 'vault', 'customer', 'currency']), 'تم إنشاء العملية', 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($transaction->load(['user', 'vault', 'customer', 'currency']));
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $transaction->update($request->validated());

        return $this->sendResponse($transaction->refresh(), 'تم تحديث العملية');
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $this->transactionService->softDelete($transaction, $this->currentUser($request));

        return $this->sendResponse(null, 'تم حذف العملية');
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->transactionService->restore($id), 'تم استعادة العملية');
    }

    public function forceDelete(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Transaction::withTrashed()->findOrFail($id)->forceDelete();

        return $this->sendResponse(null, 'تم حذف العملية نهائياً');
    }

    public function dailySummary(Request $request): JsonResponse
    {
        return $this->sendResponse($this->balanceService->getDailyNet(
            (int) $request->user()?->id,
            $request->string('date', now()->toDateString())->toString()
        ));
    }
}
