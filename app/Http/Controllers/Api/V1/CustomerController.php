<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Customers
 *
 * Manage customers owned by users and linked to their vaults.
 */
class CustomerController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
    ) {}

    /**
     * List customers
     *
     * Owner users can see all customers. Managers are scoped to their own customers.
     *
     * @authenticated
     *
     * @queryParam search string Search by customer name. Example: Ahmed
     * @queryParam category string Filter by category. Example: regular
     * @queryParam user_id integer Owner-only user filter. Example: 3
     * @queryParam is_active boolean Filter active status. Example: true
     * @queryParam with_trashed boolean Include soft-deleted rows. Example: false
     * @queryParam per_page integer Results per page. Example: 20
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()->with(['user', 'vault'])->latest();
        $query = $this->scopeToCurrentUser($query, $request);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        if ($this->isOwner($request->user()) && $request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Create customer
     *
     * Create a customer and attach it to the selected user's vault.
     *
     * @authenticated
     *
     * @response 201 {"success":true,"message":"تم إنشاء العميل"}
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $owner = $this->isOwner($request->user());
        $user = $owner && $request->filled('user_id')
            ? User::query()->findOrFail($request->integer('user_id'))
            : $this->currentUser($request);

        $vault = $user->vault()->firstOrFail();
        $data = $request->validated();
        $data['user_id'] = $user->id;
        $data['vault_id'] = $vault->id;

        return $this->sendResponse(Customer::query()->create($data), 'تم إنشاء العميل', 201);
    }

    /**
     * Show customer
     *
     * Return one customer if the current user is allowed to view it.
     *
     * @authenticated
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($customer->load(['user', 'vault']));
    }

    /**
     * Update customer
     *
     * Update customer profile fields.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث العميل"}
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $customer->update($request->validated());

        return $this->sendResponse($customer->refresh(), 'تم تحديث العميل');
    }

    /**
     * Delete customer
     *
     * Soft-delete a customer.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم حذف العميل"}
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $customer->delete();

        return $this->sendResponse(null, 'تم حذف العميل');
    }

    /**
     * Restore customer
     *
     * Owner-only endpoint that restores a soft-deleted customer.
     *
     * @authenticated
     *
     * @urlParam id integer required Customer ID. Example: 7
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $customer = Customer::withTrashed()->findOrFail($id);
        $customer->restore();

        return $this->sendResponse($customer, 'تم استعادة العميل');
    }

    /**
     * Force delete customer
     *
     * Owner-only endpoint that permanently deletes a customer.
     *
     * @authenticated
     *
     * @urlParam id integer required Customer ID. Example: 7
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Customer::withTrashed()->findOrFail($id)->forceDelete();

        return $this->sendResponse(null, 'تم حذف العميل نهائياً');
    }

    /**
     * Customer transactions
     *
     * List transactions linked to a customer.
     *
     * @authenticated
     *
     * @queryParam per_page integer Results per page. Example: 20
     */
    public function transactions(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $query = Transaction::query()->where('customer_id', $customer->id)->latest('transaction_date');

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Customer balance
     *
     * Return the customer balance in USD.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"balance_usd":250.5}}
     */
    public function balance(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(['balance_usd' => $this->balanceService->getCustomerBalance($customer->id)]);
    }
}
