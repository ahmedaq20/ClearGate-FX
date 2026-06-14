<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
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
     * @queryParam search string Search by customer name or customer code. Example: Ahmed
     * @queryParam type string Filter by customer type. Example: customer
     * @queryParam category string Filter by category. Example: regular
     * @queryParam user_id integer Owner-only user filter. Example: 3
     * @queryParam is_active boolean Filter active status. Example: true
     * @queryParam with_trashed boolean Include soft-deleted rows. Example: false
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"customer_code":"3301","name":"Ahmed","phone":"059xxxxxxx","type":"customer"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query()->with(['user', 'vault'])->latest();
        $query = $this->scopeToCurrentUser($query, $request);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('customer_code', 'like', '%'.$search.'%');
            }))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        if ($this->isOwner($request->user()) && $request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $this->sendResponse(CustomerResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    /**
     * Create customer
     *
     * Create a customer and attach it to the selected user's vault.
     *
     * @authenticated
     *
     * @bodyParam customer_code string required Manual unique customer code. Example: 3301
     * @bodyParam name string required Customer or supplier name. Example: Ahmed
     * @bodyParam phone string Customer phone number. Example: 059xxxxxxx
     * @bodyParam type string required Customer type. Must be customer or supplier. Example: customer
     * @bodyParam note string Optional note. Example: Regular remittance customer
     * @bodyParam category string Optional legacy category. Must be regular, vip, agent, or company. Example: regular
     * @bodyParam country string Optional country. Example: Palestine
     * @bodyParam user_id integer Owner-only user assignment. Example: 3
     * @bodyParam balance_usd number Optional opening balance. Example: 500
     *
     * @response 201 {"success":true,"message":"تم إنشاء العميل","data":{"id":1,"customer_code":"3301","name":"Ahmed","phone":"059xxxxxxx","type":"customer"}}
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

        return $this->sendResponse(CustomerResource::make(Customer::query()->create($data)), 'تم إنشاء العميل', 201);
    }

    /**
     * Show customer
     *
     * Return one customer if the current user is allowed to view it.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"customer_code":"3301","name":"Ahmed","phone":"059xxxxxxx","type":"customer","vault":{"id":1}}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(CustomerResource::make($customer->load(['user', 'vault'])));
    }

    /**
     * Update customer
     *
     * Update customer profile fields.
     *
     * @authenticated
     *
     * @bodyParam customer_code string Manual unique customer code. Example: 3302
     * @bodyParam name string Customer or supplier name. Example: Ahmed Updated
     * @bodyParam phone string Customer phone number. Example: 059xxxxxxx
     * @bodyParam type string Customer type. Must be customer or supplier. Example: supplier
     * @bodyParam note string Optional note. Example: Updated note
     * @bodyParam category string Optional legacy category. Must be regular, vip, agent, or company. Example: vip
     * @bodyParam country string Optional country. Example: Jordan
     * @bodyParam is_active boolean Active status. Example: true
     *
     * @response 200 {"success":true,"message":"تم تحديث العميل","data":{"id":1,"customer_code":"3302","name":"Ahmed Updated","phone":"059xxxxxxx","type":"supplier"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $customer->update($request->validated());

        return $this->sendResponse(CustomerResource::make($customer->refresh()), 'تم تحديث العميل');
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
     * @response 200 {"success":true,"message":"تم استعادة العميل","data":{"id":7,"customer_code":"3301","name":"Ahmed","type":"customer","deleted_at":null}}
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

        return $this->sendResponse(CustomerResource::make($customer), 'تم استعادة العميل');
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

        $customer = Customer::withTrashed()->findOrFail($id);

        if (! $customer->trashed()) {
            return $this->sendError('لا يمكن حذف عميل نهائياً قبل حذفه مؤقتاً', [], 422);
        }

        if ($customer->transactions()->withTrashed()->exists()) {
            return $this->sendError('لا يمكن حذف عميل نهائياً لوجود عمليات مرتبطة به', [], 409);
        }

        $customer->forceDelete();

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
