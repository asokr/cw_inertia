<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Requests\Admin\UpdateUserAccessRequest;
use App\Models\User;
use App\Services\Admin\AdminRoleService;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        private readonly AdminRoleService $roleService,
        private readonly AdminUserService $userService,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Roles/Index', [
            'users' => $this->userService->list(),
            'roles' => $this->roleService->listRoles(),
            'permissions' => $this->roleService->listPermissions(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->roleService->createRole($data['name'], $data['permissions']);
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'Не удалось создать роль. Возможно, она уже существует.');
        }

        return redirect()->back()->with('success', 'Роль создана');
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin') {
            return redirect()->back()->with('error', 'Роль super-admin нельзя редактировать');
        }

        $data = $request->validated();
        $this->roleService->updateRole($role, $data['name'], $data['permissions']);

        return redirect()->back()->with('success', 'Роль обновлена');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin') {
            return redirect()->back()->with('error', 'Роль super-admin нельзя удалить');
        }

        $this->roleService->deleteRole($role);

        return redirect()->back()->with('success', 'Роль удалена');
    }

    public function updateUserAccess(UpdateUserAccessRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $this->roleService->setUserRoles($user, $data['roles'] ?? []);
        $this->roleService->setUserPermissions($user, $data['permissions'] ?? []);

        return redirect()->back()->with('success', 'Права доступа обновлены');
    }
}