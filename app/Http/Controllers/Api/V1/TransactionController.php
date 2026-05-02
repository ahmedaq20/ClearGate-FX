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

/**
 * @group Transactions
 *
 * Manage receive/send transactions. Financial calculations are handled by TransactionService.
 */
class TransactionController extends BaseApiController
{
    public function __construct(
        private TransactionService $transactionService,
        private BalanceService $balanceService,
    ) {}

    /**
     * List transactions
     *
     * Owner users can see all transactions. Managers are scoped to their own transactions.
     *
     * @authenticated
     *
     * @queryParam date_from date Filter from transaction date. Example: 2026-05-01
     * @queryParam date_to date Filter to transaction date. Example: 2026-05-31
     * @queryParam type string Filter by transaction type. Example: receive
     * @queryParam customer_id integer Filter by customer ID. Example: 12
     * @queryParam currency string Filter by currency code. Example: USD
     * @queryParam user_id integer Owner-only user filter. Example: 3
     * @queryParam vault_id integer Filter by vault ID. Example: 2
     * @queryParam country string Filter by country. Example: Palestine
     * @queryParam min_usd number Minimum net USD value. Example: 10
     * @queryParam max_usd number Maximum net USD value. Example: 5000
     * @queryParam with_trashed boolean Include soft-deleted rows. Example: false
     * @queryParam per_page integer Results per page. Example: 20
     */
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

    /**
     * Create transaction
     *
     * Create a receive or send transaction using the current user's vault. Commission is calculated in TransactionService.
     *
     * @authenticated
     *
     * @response 201 {"success":true,"message":"تم إنشاء العملية"}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->store($request->validated(), $this->currentUser($request));

        return $this->sendResponse($transaction->load(['user', 'vault', 'customer', 'currency']), 'تم إنشاء العملية', 201);
    }

    /**
     * Show transaction
     *
     * Return one transaction if the current user is allowed to view it.
     *
     * @authenticated
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($transaction->load(['user', 'vault', 'customer', 'currency']));
    }

    /**
     * Update transaction metadata
     *
     * Update non-financial transaction fields only.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث العملية"}
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $transaction->update($request->validated());

        return $this->sendResponse($transaction->refresh(), 'تم تحديث العملية');
    }

    /**
     * Delete transaction
     *
     * Soft-delete a transaction and reverse its balance effect through TransactionService.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم حذف العملية"}
     */
    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $transaction->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $this->transactionService->softDelete($transaction, $this->currentUser($request));

        return $this->sendResponse(null, 'تم حذف العملية');
    }

    /**
     * Restore transaction
     *
     * Owner-only endpoint that restores a soft-deleted transaction and reapplies its balance effect.
     *
     * @authenticated
     *
     * @urlParam id integer required Transaction ID. Example: 10
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->transactionService->restore($id), 'تم استعادة العملية');
    }

    /**
     * Force delete transaction
     *
     * Owner-only endpoint that permanently deletes a transaction.
     *
     * @authenticated
     *
     * @urlParam id integer required Transaction ID. Example: 10
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Transaction::withTrashed()->findOrFail($id)->forceDelete();

        return $this->sendResponse(null, 'تم حذف العملية نهائياً');
    }

    /**
     * Daily transaction summary
     *
     * Return receive, send, net, and count totals for the current user on a date.
     *
     * @authenticated
     *
     * @queryParam date date Summary date. Example: 2026-05-03
     */
    public function dailySummary(Request $request): JsonResponse
    {
        return $this->sendResponse($this->balanceService->getDailyNet(
            (int) $request->user()?->id,
            $request->string('date', now()->toDateString())->toString()
        ));
    }
}
