<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Support\PermissionDisplay;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * @group Permissions
 *
 * Owner-only permission lookup with Arabic display labels.
 */
class PermissionController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()
            ->where('guard_name', 'sanctum')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission): array => PermissionDisplay::permission($permission))
            ->groupBy('group')
            ->map(fn ($items, string $group): array => [
                'group' => $group,
                'group_label' => (string) config("permissions.groups.{$group}", $group),
                'permissions' => $items->values(),
            ])
            ->values();

        return $this->sendResponse($permissions);
    }
}
