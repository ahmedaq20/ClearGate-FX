<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionDisplay
{
    /**
     * @return array{name: string, label: string}
     */
    public static function role(Role|string $role): array
    {
        $name = $role instanceof Role ? $role->name : $role;

        return [
            'name' => $name,
            'label' => (string) config("permissions.roles.{$name}", $name),
        ];
    }

    /**
     * @return array{name: string, label: string, group: string, group_label: string}
     */
    public static function permission(Permission|string $permission): array
    {
        $name = $permission instanceof Permission ? $permission->name : $permission;
        $metadata = config('permissions.permissions', [])[$name] ?? [];
        $group = (string) ($metadata['group'] ?? self::guessGroup($name));

        return [
            'name' => $name,
            'label' => (string) ($metadata['label'] ?? $name),
            'group' => $group,
            'group_label' => (string) config("permissions.groups.{$group}", $group),
        ];
    }

    /**
     * @param  iterable<int, Role|string>  $roles
     * @return Collection<int, array{name: string, label: string}>
     */
    public static function roles(iterable $roles): Collection
    {
        return collect($roles)->map(fn (Role|string $role): array => self::role($role))->values();
    }

    /**
     * @param  iterable<int, Permission|string>  $permissions
     * @return Collection<int, array{name: string, label: string, group: string, group_label: string}>
     */
    public static function permissions(iterable $permissions): Collection
    {
        return collect($permissions)->map(fn (Permission|string $permission): array => self::permission($permission))->values();
    }

    private static function guessGroup(string $permission): string
    {
        return match (str($permission)->before('.')->toString()) {
            'transaction' => 'transactions',
            'customer' => 'customers',
            'vault' => 'vaults',
            'currency', 'exchange_rate' => 'currencies',
            'report' => 'reports',
            'user' => 'users',
            'settings' => 'settings',
            'archive' => 'archive',
            'notification' => 'notifications',
            default => 'settings',
        };
    }
}
