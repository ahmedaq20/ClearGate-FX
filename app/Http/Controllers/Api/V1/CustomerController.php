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

class CustomerController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
    ) {}

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

    public function show(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($customer->load(['user', 'vault']));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $customer->update($request->validated());

        return $this->sendResponse($customer->refresh(), 'تم تحديث العميل');
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $customer->delete();

        return $this->sendResponse(null, 'تم حذف العميل');
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $customer = Customer::withTrashed()->findOrFail($id);
        $customer->restore();

        return $this->sendResponse($customer, 'تم استعادة العميل');
    }

    public function forceDelete(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Customer::withTrashed()->findOrFail($id)->forceDelete();

        return $this->sendResponse(null, 'تم حذف العميل نهائياً');
    }

    public function transactions(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $query = Transaction::query()->where('customer_id', $customer->id)->latest('transaction_date');

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    public function balance(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $customer->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(['balance_usd' => $this->balanceService->getCustomerBalance($customer->id)]);
    }
}
