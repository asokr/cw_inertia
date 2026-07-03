<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class AdminUserService
{
    public function list(?array $roleNames = null): Collection
    {
        $query = User::query()
            ->where('name', '!=', 'admin')
            ->select(['id', 'name', 'surname', 'email'])
            ->with([
                'roles' => fn ($q) => $q->select(['id', 'name']),
                'permissions' => fn ($q) => $q->select(['id', 'name']),
            ])
            ->orderBy('name');

        if (! empty($roleNames)) {
            $query->role($roleNames);
        }

        return $query->get()->map(function (User $user) {
            $user->setAttribute('full_name', $user->getFullName());

            return $user;
        });
    }

    public function search(string $query): Collection
    {
        return User::query()
            ->where('name', 'like', "%{$query}%")
            ->orWhere('surname', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->with(['roles', 'permissions'])
            ->limit(20)
            ->get()
            ->map(function (User $user) {
                $user->setAttribute('full_name', $user->getFullName());

                return $user;
            });
    }

    public function getUser(User $user): User
    {
        $user->load(['roles', 'permissions']);

        return $user;
    }

    public function update(User $user, array $data): User
    {
        if (! empty($data['name'])) {
            $user->name = $data['name'];
        }

        if (array_key_exists('surname', $data)) {
            $user->surname = $data['surname'] ?? '';
        }

        if (! empty($data['email'])) {
            $user->email = $data['email'];
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return $user->fresh(['roles', 'permissions']);
    }

    public function delete(array $ids): void
    {
        User::destroy($ids);
    }
}