<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminRoleService
{
    public function listRoles(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'super-admin')
            ->orderBy('name')
            ->with('permissions')
            ->get();
    }

    public function listPermissions(): Collection
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function createRole(string $name, array $permissionIds): Role
    {
        $role = Role::create([
            'name' => $name,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissionIds);

        return $role->load('permissions');
    }

    public function updateRole(Role $role, string $name, array $permissionIds): Role
    {
        $role->name = $name;
        $role->save();
        $role->syncPermissions($permissionIds);

        return $role->fresh('permissions');
    }

    public function deleteRole(Role $role): void
    {
        $role->syncPermissions([]);
        $role->delete();
    }

    public function setUserRoles(User $user, array $roleIds): void
    {
        $user->syncRoles($roleIds);
    }

    public function setUserPermissions(User $user, array $permissionIds): void
    {
        $user->syncPermissions($permissionIds);
    }
}