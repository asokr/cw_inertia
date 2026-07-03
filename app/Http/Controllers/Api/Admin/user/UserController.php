<?php

namespace App\Http\Controllers\Api\Admin\user;

use Throwable;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /*
    / Если $request пустой - функция отдаёт всеx пользователей
    / Если $request содержит id пользователя, то функция вернёт пользователя
    */

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'integer',
            'roles' => 'array',
            'roles.*' => 'string'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->userId) {
            $users = User::where('id', $request->userId)
                ->select('id', 'name', 'surname', 'email')
                ->with([
                    'roles' => function ($query) {
                        $query->select('id', 'name');
                    }
                ])
                ->with('permissions')
                ->get();
        } else if (!isset($request->roles)) {
            $users = User::where('name', '!=', 'admin')->orderBy('name')->with('roles')->with('permissions')->get();
        }

        // Если на фронте отфильтровали пользователей по роли
        if (isset($request->roles)) {
            $users = User::role($request->roles)->with('roles')->get();
        }
        // Для фильтрации и поисков нужно как-то более рационально показать пользователя.
        // На фронте не всегда это предоставляется возможным, поэтому сделаем здесь.
        foreach ($users as $key => $user) {
            $users[$key]['full_name'] = $user->getFullName();
        }

        return response()->json(["success" => true, "messages" => ["Список пользователей"], 'data' => $users], 200);

    }

    /*
    / $request содержит новые данные пользователя
    */

    public function edit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:users,id',
            'name' => 'required|max:255',
            'surname' => 'max:255',
            'email' => 'required|string|email|max:190|unique:users,email,' . $request->id,
            'password' => 'confirmed',
        ] );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user = User::find($request->id);

        if ($request->name && $request->name != '') {
            $user->name = $request->name;
        }
        if ($request->surname && $request->surname != '') {
            $user->surname = $request->surname;
        }
        if ($request->email && $request->email != '') {
            $user->email = $request->email;
        }
        if ($request->password && $request->password != '') {
            $user->password = Hash::make($request->password);
        }


        $user->save();


        return response()->json(["success" => true, "messages" => ["Пользователь обновлён"], 'data' => $user], 200);
    }

    /*
    /  ids - array of users id
    */
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            // 'ids.*' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }


        try {

            User::destroy($request->ids);

            return response()->json(["success" => true, "messages" => ["Пользователь удалён"]], 200);

        } catch (Throwable $err) {
            return response()->json(["success" => false, "messages" => ["Ошибка при удалении"], "err" => $err->getMessage()], 422);
        }
    }

    /*
    / $request содержит массив-фильтр для поиска пользователя по различным параметрам
    */

    public function findUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|min:2',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $query = $request->q;

        $users = User::where('name', 'like', "%{$query}%")
            ->orWhere('surname', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->get();

        return response()->json(["success" => true, "messages" => ["Список пользователей"], 'data' => $users], 200);

    }

    public function getLastRegisteredUsers()
    {
        $data = array();
        $data['users'] = User::role('Подписчик')->limit(10)->orderBy('created_at', 'desc')->get();

        return response()->json(["success" => true, "messages" => ["Список пользователей"], 'data' => $data], 200);

    }
}
