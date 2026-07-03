<?php

namespace App\Http\Controllers\Api\Admin\subscribers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Subscribers\Subscribers;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;

class PlansController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->filter) {
            switch ($request->filter) {
                case 'available':
                    $data = SubscribersPlans::where('status', 1)->get();
                    break;

                default:
                    $data = SubscribersPlans::all();
                    break;
            }
        } else {
            $data = SubscribersPlans::all();
        }


        return response()->json(["success" => true, "messages" => ["Тарифы для подписчиков"], 'data' => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'price' => 'required|numeric',
            'duration' => 'required|integer',
            'limits_plan' => '',
            'limits_month' => '',
            'permissions' => 'required|array',
            'status' => 'required|boolean',
            'hidden' => 'required|boolean',
        ], [
            'required' => 'Не все данные указаны',
            'price.numeric' => 'Указывайте цену целым числом',
            'duration.integer' => 'Продолжительность указывается в днях',
            'permissions.required' => 'Для тарифа необходимо хотя-бы одно разрешение',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $value = $request->limits_plan;
        $limits_plan = array();
        if ($value) {
            $array = explode('|', $value);
            foreach ($array as $str) {
                list($key, $item) = explode(':', $str);
                $limits_plan[$key] = $item;
            }
        }

        $value = $request->limits_month;
        $limits_month = array();
        if ($value) {
            $array = explode('|', $value);
            foreach ($array as $str) {
                list($key, $item) = explode(':', $str);
                $limits_month[$key] = $item;
            }
        }
        $model = SubscribersPlans::create([
            'name' => $request->name,
            'price' => $request->price,
            'duration' => $request->duration,
            'description' => $request->description ?? '',
            'limits_plan' => $limits_plan,
            'limits_month' => $limits_month,
            'permissions' => $request->permissions,
            'status' => $request->status,
            'hidden' => $request->hidden,
        ]);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при добавлении тарифа']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Тариф создан"]], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $data = SubscribersPlans::find($id);

        if (!$data) {
            return response()->json(["success" => false, "messages" => ['Такого тарифа не существует']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Тариф найден"], 'data' => $data], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'price' => 'required|numeric',
            'duration' => 'required|integer',
            'limits_plan' => '',
            'limits_month' => '',
            'permissions' => 'required|array',
            'status' => 'required|boolean',
            'hidden' => 'required|boolean',
        ], [
            'required' => 'Не все данные указаны',
            'price.integer' => 'Указывайте цену в целым числом',
            'duration.integer' => 'Продолжительность указывается в днях',
            'permissions.required' => 'Для тарифа необходимо хотя-бы одно разрешение',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }


        $model = SubscribersPlans::find($id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при обновлении тарифа']], 200);
        }
        $value = $request->limits_plan;
        $limits_plan = array();
        if ($value) {
            $array = explode('|', $value);
            foreach ($array as $str) {
                list($key, $item) = explode(':', $str);
                $limits_plan[$key] = $item;
            }
        }

        $value = $request->limits_month;
        $limits_month = array();
        if ($value) {
            $array = explode('|', $value);
            foreach ($array as $str) {
                list($key, $item) = explode(':', $str);
                $limits_month[$key] = $item;
            }
        }

        $model->update([
            'name' => $request->name,
            'price' => $request->price,
            'duration' => $request->duration,
            'description' => $request->description ?? '',
            'limits_plan' => $limits_plan,
            'limits_month' => $limits_month,
            'permissions' => $request->permissions,
            'status' => $request->status,
            'hidden' => $request->hidden,
        ]);

        // Если изменили условия тарифа, нужно добавить/удалить лимиты у текущих подписчиков
        // И синхронизировать разрешения
        $subscriptions = SubscribersSubscriptions::where('plan_id', $id)->get();
        if ($subscriptions && $subscriptions->count()) {
            foreach ($subscriptions as $subscription) {
                // Добавим месячные лимиты, если появились новые

                $new_limit_month = array();
                foreach ($limits_month as $k => $limit) {
                    if (array_key_exists($k, $subscription->limits_month)) {
                        foreach ($subscription->limits_month as $s => $month) {
                            if ($k == $s) {
                                $new_limit_month[$k] = $month;
                            }
                        }
                    } else {
                        $new_limit_month[$k] = $limit;
                    }
                }
                // Добавим лимиты плана, если появились новые
                $new_limit_plan = array();
                foreach ($limits_plan as $k => $limit) {
                    if (array_key_exists($k, $subscription->limits_plan)) {
                        foreach ($subscription->limits_plan as $s => $plan) {
                            if ($k == $s) {
                                $new_limit_plan[$k] = $plan;
                            }
                        }
                    } else {
                        $new_limit_plan[$k] = $limit;
                    }
                }
                $subscription->limits_plan = $new_limit_plan;
                $subscription->limits_month = $new_limit_month;
                $subscription->save();

                $subscriber = Subscribers::find($subscription->subscribers_id);
                $user = $subscriber->getUser();
                $user->syncPermissions($model->permissions);
            }
        }


        return response()->json(["success" => true, "messages" => ["Тариф обновлён"]], 200);
    }


    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:subscribers_plans,id',
            'status' => 'required|boolean',
        ], [
            'required' => 'Не все данные указаны',
            'id.exists' => 'Такого тарифного плана нет',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }
        $model = SubscribersPlans::find($request->id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Такого тарифа не существует']], 200);
        }

        $model->status = $request->status;
        $model->save();

        return response()->json(["success" => true, "messages" => ["Статус изменён"]], 200);
    }
}
