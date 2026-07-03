<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\Admin\AdminRoleService;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
        private readonly AdminRoleService $roleService,
    ) {
    }

    public function index(IndexUserRequest $request): Response
    {
        $filters = $request->validated();
        $roleFilter = $filters['role'] ?? null;

        $users = ! empty($filters['search'])
            ? $this->userService->search($filters['search'])
            : $this->userService->list($roleFilter ? [$roleFilter] : null);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roles' => $this->roleService->listRoles(),
            'filters' => [
                'role' => $roleFilter,
                'search' => $filters['search'] ?? '',
            ],
        ]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Edit', [
            'user' => $this->userService->getUser($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->update($user, $request->validated());

        return redirect()->route('admin.users.index')->with('success', 'Пользователь обновлён');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->userService->delete([$user->id]);

        return redirect()->route('admin.users.index')->with('success', 'Пользователь удалён');
    }
}