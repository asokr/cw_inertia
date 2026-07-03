<?php

namespace App\Http\Controllers\Api\Admin\subscribers;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Subscribers;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;

class SubscriptionsController extends Controller
{
    use SubscriptionsTrait;
    public function changeStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:subscribers_subscriptions,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = SubscribersSubscriptions::find($request->id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при смене статуса подписки']], 200);
        }

        $model->status = !$model->status;

        if ($model->status) {
            // Если мы активируем подписку, то с текущего времени до времени по тарифу
            $modelPlan = SubscribersPlans::find($model->plan_id);
            $model->start_date = Carbon::now();
            $model->end_date = Carbon::now()->addDays($modelPlan->duration);
            // Добавим лимиты по месячному тарифу
            $limits = $model->limits_month;
            foreach ($modelPlan->limits_month as $limit => $value) {
                $limits[$limit] = $value;
            }
            $model->limits_month = $limits;
        }

        $model->save();

        if ($model->status) {
            // Проверяем лимиты после сохранения текущей подписки
            // т.к. в трейте SubscriptionsTrait мы делаем выборку по активным подпискам.
            foreach ($model->limits_plan as $limit => $value) {
                $this->syncLimits($model->subscribers_id, $limit);
            }
        }

        $modelSubscriber = Subscribers::find($model->subscribers_id);


        // Соберём все разрешения, которые должны быть у юзера в соответствии с его подписками
        $permissions = array();
        $modelPlan = SubscribersPlans::find($model->plan_id);
        $permissions = $modelPlan->permissions;
        $permissions = array_merge($permissions, ['subscriber']);

        $modelSubscriber->user->syncPermissions($permissions);

        return response()->json(["success" => true, "messages" => ["Статус подписки обновлён"]], 200);
    }

    public function renewSubcription(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:subscribers_subscriptions,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = SubscribersSubscriptions::find($request->id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при продлении подписки']], 200);
        }

        $modelPlan = SubscribersPlans::find($model->plan_id);
        if (!$modelPlan) {
            return response()->json(["success" => false, "messages" => ['Ошибка при продлении подписки']], 200);
        }

        $model->status = 1;
        $model->end_date = Carbon::parse($model->end_date)->addDays($modelPlan->duration);

        // Добавим лимиты по месячному тарифу
        $limits = $model->limits_month;
        foreach ($modelPlan->limits_month as $limit => $value) {
            $limits[$limit] = $value;
        }
        $model->limits_month = $limits;

        $model->save();

        return response()->json(["success" => true, "messages" => ["Подписка продлена"]], 200);
    }

    public function destroy($id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:subscribers_subscriptions,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = SubscribersSubscriptions::find($id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при удалении']], 200);
        }

        $model->delete();

        return response()->json(["success" => true, "messages" => ["Подписка удалена"]], 200);
    }
}
