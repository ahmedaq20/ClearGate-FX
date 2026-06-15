<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\SyncRolePermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Support\PermissionDisplay;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @group Roles
 *
 * Owner-only role and role-permission management.
 */
class RoleController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'sanctum')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => $this->rolePayload($role));

        return $this->sendResponse($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::query()->create([
            'name' => $data['name'],
            'guard_name' => 'sanctum',
        ]);

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->sendResponse($this->rolePayload($role->load('permissions')), 'تم إنشاء الدور', 201);
    }

    public function show(Role $role): JsonResponse
    {
        return $this->sendResponse($this->rolePayload($this->ensureSanctumRole($role)->load('permissions')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->ensureSanctumRole($role);

        if ($this->isSystemRole($role)) {
            return $this->sendError('لا يمكن تعديل اسم دور نظامي', [], 422);
        }

        $role->update($request->validated());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->sendResponse($this->rolePayload($role->refresh()->load('permissions')), 'تم تحديث الدور');
    }

    public function destroy(Role $role): JsonResponse
    {
        $role = $this->ensureSanctumRole($role);

        if ($role->name === 'owner') {
            return $this->sendError('لا يمكن حذف دور المالك', [], 422);
        }

        if ($role->name === 'manager' && $this->isSystemRole($role)) {
            return $this->sendError('لا يمكن حذف دور المدير الافتراضي', [], 422);
        }

        if ($this->isSystemRole($role)) {
            return $this->sendError('لا يمكن حذف دور نظامي', [], 422);
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->sendResponse(null, 'تم حذف الدور');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->ensureSanctumRole($role);
        $permissions = $request->validated('permissions');

        if ($role->name === 'owner') {
            $missingCriticalPermissions = array_values(array_diff(
                config('permissions.critical_owner_permissions', []),
                $permissions
            ));

            if ($missingCriticalPermissions !== []) {
                return $this->sendError('لا يمكن إزالة الصلاحيات الأساسية من دور المالك', [
                    'permissions' => $missingCriticalPermissions,
                ], 422);
            }
        }

        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->sendResponse($this->rolePayload($role->refresh()->load('permissions')), 'تم تحديث صلاحيات الدور');
    }

    private function ensureSanctumRole(Role $role): Role
    {
        abort_unless($role->guard_name === 'sanctum', 404);

        return $role;
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, config('permissions.system_roles', []), true);
    }

    /**
     * @return array{id: int, name: string, label: string, guard_name: string, is_system: bool, permissions: mixed}
     */
    private function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            ...PermissionDisplay::role($role),
            'guard_name' => $role->guard_name,
            'is_system' => $this->isSystemRole($role),
            'permissions' => PermissionDisplay::permissions($role->permissions),
        ];
    }
}
