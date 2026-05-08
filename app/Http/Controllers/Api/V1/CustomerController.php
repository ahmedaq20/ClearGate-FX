<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ArchiveService;
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
        private ArchiveService $archiveService,
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
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"name":"Ahmed","category":"regular","balance_usd":"250.0000","is_active":true}]}
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
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $owner = $this->isOwner($request->user());
        $user = $owner && $request->filled('user_id')
            ? User::query()->findOrFail($request->integer('user_id'))
            : $this->currentUser($request);

        $vault = $user->vault()->first();

        if ($vault === null) {
            return $this->sendError('صندوق المستخدم غير موجود. يرجى التواصل مع المسؤول.', [], 409);
        }

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
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"name":"Ahmed","balance_usd":"250.0000","vault":{"id":1}}}
     * @response 403 {"success":false,"message":"غير مصرح"}
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
     * @response 403 {"success":false,"message":"غير مصرح"}
     * @response 422 {"success":false,"message":"Validation Error"}
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
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $snapshot = $customer->attributesToArray();
        $customer->delete();

        AuditLog::record(
            action: 'customer.deleted',
            model: $customer,
            userId: (int) $request->user()?->id,
            oldValues: $snapshot
        );

        $this->archiveService->archive($customer, $this->currentUser($request), 'customer.deleted', $snapshot);

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
     *
     * @response 200 {"success":true,"message":"تم استعادة العميل","data":{"id":7,"name":"Ahmed","deleted_at":null}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $customer = Customer::withTrashed()->find($id);

        if ($customer === null) {
            return $this->sendError('العميل غير موجود', [], 404);
        }

        if (! $customer->trashed()) {
            return $this->sendError('لا يمكن استعادة عميل غير محذوف', [], 422);
        }

        $customer->restore();

        AuditLog::record(
            action: 'customer.restored',
            model: $customer,
            userId: (int) $request->user()?->id,
            newValues: $customer->attributesToArray()
        );

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
     *
     * @response 200 {"success":true,"message":"تم حذف العميل نهائياً"}
     * @response 403 {"success":false,"message":"غير مصرح"}
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
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"type":"receive","net_usd_value":"100.0000"}]}
     * @response 403 {"success":false,"message":"غير مصرح"}
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
