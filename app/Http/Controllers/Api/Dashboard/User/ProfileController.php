<?php

namespace App\Http\Controllers\Api\Dashboard\User;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user_id = Auth::id();

        $data = User::find($user_id);

        return response()->json(["success" => true, "messages" => ["Данные профиля получены"], "data" => $data], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|min:3|max:190',
                'surname' => 'required|string|min:3|max:190',
            ],
            [
                'name.required' => 'Необходимо заполнить имя',
                'surname.required' => 'Необходимо заполнить фамилию'
            ]
        );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user_id = Auth::id();

        $user = User::find($user_id);
        $user->name = $request->name;
        $user->surname = $request->surname;


        $user->save();

        return response()->json(["success" => true, "messages" => ["Данные профиля обновлены"], "data" => $user], 200);
    }

}
