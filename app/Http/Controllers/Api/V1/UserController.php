<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\User\ChangeUserRoleRequest;
use App\Http\Requests\User\SetVaultBalanceRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Services\VaultService;
use App\Support\PermissionDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Users
 *
 * Owner-only user management endpoints.
 */
class UserController extends BaseApiController
{
    public function __construct(
        private VaultService $vaultService,
    ) {}

    /**
     * List users
     *
     * Owner-only endpoint returning paginated users with their vaults.
     *
     * @authenticated
     *
     * @queryParam search string Search by user name or email. Example: manager
     * @queryParam is_active boolean Filter by active status. Example: true
     * @queryParam with_trashed boolean Include soft-deleted users. Example: false
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":2,"name":"Manager","email":"manager@example.com","is_active":true,"vault":{"id":2,"balance_usd":"1000.0000"}}]}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function index(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $query = User::query()->with(['vault', 'roles'])->latest();

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $query
            ->when($request->filled('search'), fn ($query) => $query
                ->where('name', 'like', '%'.$request->string('search')->toString().'%')
                ->orWhere('email', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')));

        return $this->sendResponse($query
            ->paginate($request->integer('per_page', 20))
            ->through(fn (User $user): array => $this->userPayload($user)));
    }

    /**
     * Create user
     *
     * Owner-only endpoint that creates a user, creates their vault, and optionally assigns the manager role.
     *
     * @authenticated
     *
     * @response 201 {"success":true,"message":"تم إنشاء المستخدم","data":{"id":2,"name":"Manager","email":"manager@example.com","vault":{"id":2,"balance_usd":1000}}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $initialBalance = (float) ($data['initial_balance'] ?? 0);

        $user = DB::transaction(function () use ($data, $initialBalance): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'initial_balance' => $initialBalance,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->vaultService->createForUser($user, $initialBalance);
            $user->assignRole($data['role']);

            return $user;
        });

        return $this->sendResponse($this->userPayload($user->load(['vault', 'roles'])), 'تم إنشاء المستخدم', 201);
    }

    /**
     * Show user
     *
     * Owner-only endpoint returning a user with vault and customers.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":2,"name":"Manager","email":"manager@example.com","vault":{"id":2},"customers":[]}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse($this->userPayload($user->load(['vault', 'customers', 'roles']), includePermissions: true));
    }

    /**
     * Update user
     *
     * Owner-only endpoint for updating user profile fields and active status.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث المستخدم","data":{"id":2,"name":"Manager","email":"manager@example.com"}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        return $this->sendResponse($this->userPayload($user->refresh()->load(['vault', 'roles'])), 'تم تحديث المستخدم');
    }

    /**
     * Delete user
     *
     * Owner-only endpoint that soft-deletes a user.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم حذف المستخدم"}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        if ($this->isLastOwner($user)) {
            return $this->sendError('لا يمكن حذف آخر مستخدم مالك', [], 422);
        }

        $user->delete();

        return $this->sendResponse(null, 'تم حذف المستخدم');
    }

    /**
     * Restore user
     *
     * Owner-only endpoint that restores a soft-deleted user.
     *
     * @authenticated
     *
     * @urlParam id integer required User ID. Example: 2
     *
     * @response 200 {"success":true,"message":"تم استعادة المستخدم","data":{"id":2,"name":"Manager"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return $this->sendResponse($this->userPayload($user->load(['vault', 'roles'])), 'تم استعادة المستخدم');
    }

    /**
     * Toggle user active status
     *
     * Owner-only endpoint that activates or deactivates a user account.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث حالة المستخدم","data":{"id":2,"is_active":false}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        if ($user->is_active && $this->isLastOwner($user)) {
            return $this->sendError('لا يمكن تعطيل آخر مستخدم مالك', [], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return $this->sendResponse($this->userPayload($user->refresh()->load(['vault', 'roles'])), 'تم تحديث حالة المستخدم');
    }

    /**
     * Change user role
     *
     * Owner-only endpoint that replaces the user's assigned role.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث دور المستخدم","data":{"id":2,"roles":[{"name":"manager","label":"مدير"}]}}
     * @response 422 {"success":false,"message":"لا يمكن تغيير دور آخر مستخدم مالك"}
     */
    public function changeRole(ChangeUserRoleRequest $request, User $user): JsonResponse
    {
        if ($this->isLastOwner($user) && $request->validated('role') !== 'owner') {
            return $this->sendError('لا يمكن تغيير دور آخر مستخدم مالك', [], 422);
        }

        $user->syncRoles([$request->validated('role')]);

        return $this->sendResponse($this->userPayload($user->refresh()->load(['vault', 'roles'])), 'تم تحديث دور المستخدم');
    }

    /**
     * Set user vault balance
     *
     * Owner-only endpoint that updates a user's initial balance, updates their vault, and recalculates current vault balance.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث رصيد صندوق المستخدم","data":{"id":2,"vault":{"id":2,"initial_balance":"5000.0000","balance_usd":"5000.0000"}}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function setVaultBalance(SetVaultBalanceRequest $request, User $user): JsonResponse
    {
        $user->update(['initial_balance' => $request->float('initial_balance')]);
        $vault = $user->vault()->firstOrFail();
        $vault->update(['initial_balance' => $request->float('initial_balance')]);
        $this->vaultService->recalculateBalance($vault);

        return $this->sendResponse($this->userPayload($user->refresh()->load(['vault', 'roles'])), 'تم تحديث رصيد صندوق المستخدم');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user, bool $includePermissions = false): array
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'initial_balance' => $user->initial_balance,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'roles' => PermissionDisplay::roles($user->roles),
            'vault' => $user->relationLoaded('vault') ? $user->vault : null,
        ];

        if ($user->relationLoaded('customers')) {
            $payload['customers'] = $user->customers;
        }

        if ($includePermissions) {
            $payload['permissions'] = PermissionDisplay::permissions($user->getAllPermissions());
        }

        return $payload;
    }

    private function isLastOwner(User $user): bool
    {
        return $user->hasRole('owner', 'sanctum')
            && User::role('owner', 'sanctum')->count() <= 1;
    }
}
