<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /*
    / Если $request пустой - функция отдаёт все роли
    / Если $request содержит id роли, то функция вернёт роль
    */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'roleId' => 'integer'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->roleId) {
            $roles = Role::where('id', $request->roleId)->with('permissions')->get();
        } else {
            $roles = Role::orderBy('name')->where('name', '!=', 'super-admin')->with('permissions')->get();
        }
        return response()->json(["success" => true, "messages" => ["Роли получены"], "data" => $roles], 200);
    }

    /*
    / $request содержит название роли и её разрешения
    /
    */
    public function create(Request $request) {
        $validator = Validator::make($request->all(), [
            'name' => 'required|max:255',
            'permissions' => 'required',
            'permissions.*' => 'required|integer|exists:permissions,id'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $role = Role::findByName($request->name);
            if ($role) {
                return response()->json(["success" => false, "messages" => ["Такая роль уже существует"], 'data' => $role], 200);
            }
        } catch (\Throwable $th) {
            //throw $th;
        }


        $role = Role::create(['name' => $request->name, 'guard_name' => 'api']);
        $role->syncPermissions($request->permissions);

        return response()->json(["success" => true, "messages" => ["Роль создана. Разрешения даны."], 'data' => $role], 200);
    }

    /*
    / $request содержит id роли, название роли и её разрешения
    /
    */
    public function update(Request $request) {
        $validator = Validator::make($request->all(), [
            'roleId' => 'required|integer',
            'name' => 'required|max:255',
            'permissions' => 'required',
            'permissions.*' => 'required|integer|exists:permissions,id'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $role = Role::find($request->roleId);
            if ($role) {
                $role->name = $request->name;
                $role->syncPermissions($request->permissions);
                $role->save();
                return response()->json(["success" => true, "messages" => ["Разрешения для роли установлены"], 'data' => $role], 200);
            } else {
                return response()->json(["success" => false, "messages" => ["Роль не найдена"]], 200);
            }
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["catch: Роль не найдена"]], 200);
        }
    }

    /*
    / $request содержит id роли
    /
    */
    public function delete(Request $request) {
        $validator = Validator::make($request->all(), [
            'roleId' => 'required|integer'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $role = Role::find($request->roleId);
            if ($role) {
                // Пока так - перед удалением роли уберем все разрешения
                $permissions = array();
                $role->syncPermissions($permissions);

                $role->delete();
                return response()->json(["success" => true, "messages" => ["Роль успешно удалена"]], 200);
            } else {
                return response()->json(["success" => false, "messages" => ["Роль не найдена"]], 200);
            }
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["catch: Роль не найдена"]], 200);
        }
    }

    /*
    / $request содержит id пользователя, и массив ролей
    /
    */
    public function setUserRoles(Request $request) {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|integer',
            'roles' => 'required',
            'roles.*' => 'required|integer|exists:roles,id'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $user = User::find($request->userId);

            if ($user) {

                $user->syncRoles($request->roles);


                return response()->json(["success" => true, "messages" => ["Роли назнечены"]], 200);
            } else {
                return response()->json(["success" => false, "messages" => ["Пользователь не найден"]], 200);
            }
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["catch: Пользователь не найден"]], 200);
        }
    }

    /*
    / $request: Role name
    / return: Array of users with this role
    */
    public function getUsersByRole(Request $request) {
        $validator = Validator::make($request->all(), [
            'role_name' => 'required|exists:roles,name'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $users = User::role($request->role_name)->get();
            return response()->json(["success" => true, "messages" => ["Пользователи получены"], "data" => $users], 200);
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => ["catch: Возникла ошибка | getUsersByRole"]], 200);
        }
    }
}
