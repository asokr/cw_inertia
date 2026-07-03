<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Controller;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Validator;

class PermissionsController extends Controller
{
    /*
    / Если $request пустой - функция отдаёт все разрешения
    / Если $request содержит роль, то отдаются разрешения для роли
    */
    public function getPermissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'roleId' => 'integer'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            if ($request->roleId) {
                $role = Role::find($request->roleId);
                if ($role) {
                    $permissions = $role->permissions;
                    if (!$permissions) {
                        return response()->json(["success" => true, "messages" => ["У роли нет разрешений."], "data" => []], 200);
                    }
                } else {
                    return response()->json(["success" => false, "messages" => ["Роль не найдена"]], 200);
                }
            } else {
                $permissions = Permission::orderBy('name')->get();
            }
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["catch: Роль не найдена"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Разрешения получены"], "data" => $permissions], 200);
    }

    /*
    /  $request должен содержать массив разрешений, которые на прямую устанавливаются пользователю
    */
    public function getUserPermissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user = User::find($request->user_id);

        $permissions = $user->getAllPermissions();

        return response()->json(["success" => true, "messages" => ["Разрешения установлены"], "data" => $permissions], 200);
    }

    /*
    /  $request должен содержать userId и массив permissions,
    /  которые будут назначены пользователю напрямую.
    */
    public function setUserPermissions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer|exists:users,id',
            'permissions' => 'present|array',
            'permissions.*' => 'required|integer|exists:permissions,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $user = User::find($request->userId);
            if (!$user) {
                return response()->json(["success" => false, "messages" => ["Пользователь не найден"]], 200);
            }

            $user->syncPermissions($request->permissions ?? []);

            return response()->json(["success" => true, "messages" => ["Персональные разрешения пользователя обновлены"]], 200);
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["Ошибка при обновлении разрешений пользователя"]], 200);
        }
    }

}
