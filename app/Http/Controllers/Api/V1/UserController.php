<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\User\SetVaultBalanceRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends BaseApiController
{
    public function __construct(
        private VaultService $vaultService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $query = User::query()->with('vault')->latest();

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query
            ->when($request->filled('search'), fn ($query) => $query
                ->where('name', 'like', '%'.$request->string('search')->toString().'%')
                ->orWhere('email', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $initialBalance = (float) ($data['initial_balance'] ?? 0);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'initial_balance' => $initialBalance,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->vaultService->createForUser($user, $initialBalance);

        if (isset($data['role']) && method_exists($user, 'assignRole')) {
            $user->assignRole($data['role']);
        }

        return $this->sendResponse($user->load('vault'), 'تم إنشاء المستخدم', 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($user->load(['vault', 'customers']));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return $this->sendResponse($user->refresh()->load('vault'), 'تم تحديث المستخدم');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $user->delete();

        return $this->sendResponse(null, 'تم حذف المستخدم');
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return $this->sendResponse($user->load('vault'), 'تم استعادة المستخدم');
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $user->update(['is_active' => ! $user->is_active]);

        return $this->sendResponse($user->refresh(), 'تم تحديث حالة المستخدم');
    }

    public function setVaultBalance(SetVaultBalanceRequest $request, User $user): JsonResponse
    {
        $user->update(['initial_balance' => $request->float('initial_balance')]);
        $vault = $user->vault()->firstOrFail();
        $vault->update(['initial_balance' => $request->float('initial_balance')]);
        $this->vaultService->recalculateBalance($vault);

        return $this->sendResponse($user->refresh()->load('vault'), 'تم تحديث رصيد صندوق المستخدم');
    }
}
