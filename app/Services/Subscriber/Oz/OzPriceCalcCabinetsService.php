<?php

namespace App\Services\Subscriber\Oz;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OzPriceCalcCabinetsService
{
    public function index(Request $request)
    {
        $cabinets = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'messages' => ['Список кабинетов Ozon'],
            'data' => $cabinets,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255|unique:oz_price_calc_cabinets,client_id,NULL,id,user_id,' . $request->user()->id,
            'apikey' => 'required|string',
        ], [
            'required' => 'Заполните обязательные поля',
            'client_id.unique' => 'Такой client_id уже добавлен для вашего аккаунта',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $subscriber = $request->user()->subscriber;

        if ($subscriber) {
            $userSubscriptions = SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
            ])->first();

            $limits = $userSubscriptions?->limits_plan ?? [];

            if (isset($limits['oz_price_calc_clients']) && ! ToolLimits::canUsePlanLimit($request->user(), $limits, 'oz_price_calc_clients')) {
                return response()->json([
                    'success' => false,
                    'messages' => ['Вы исчерпали лимит на количество кабинетов.'],
                ], 200);
            }

            $updatedLimits = isset($limits['oz_price_calc_clients'])
                ? ToolLimits::applyPlanLimitConsumption($request->user(), $limits, 'oz_price_calc_clients')
                : null;

            if ($updatedLimits !== null) {
                SubscribersSubscriptions::where([
                    'subscribers_id' => $subscriber->id,
                ])->update([
                    'limits_plan' => $updatedLimits,
                ]);
            }
        }

        $cabinet = OzPriceCalcCabinet::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'client_id' => $request->client_id,
            'apikey' => $request->apikey,
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет добавлен'],
            'data' => $cabinet,
        ], 200);
    }

    public function show(Request $request, int $id)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($id);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет найден'],
            'data' => $cabinet,
        ], 200);
    }

    public function update(Request $request, int $id)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($id);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'client_id' => 'required|string|max:255|unique:oz_price_calc_cabinets,client_id,' . $cabinet->id . ',id,user_id,' . $request->user()->id,
            'apikey' => 'required|string',
        ], [
            'required' => 'Заполните обязательные поля',
            'client_id.unique' => 'Такой client_id уже добавлен для вашего аккаунта',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet->update([
            'name' => $request->name,
            'client_id' => $request->client_id,
            'apikey' => $request->apikey,
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет обновлён'],
            'data' => $cabinet->fresh(),
        ], 200);
    }

    public function destroy(Request $request, int $id)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($id);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $cabinet->delete();

        $subscriber = $request->user()->subscriber;

        if ($subscriber) {
            $userSubscriptions = SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
            ])->first();

            $limits = $userSubscriptions?->limits_plan ?? [];

            if (isset($limits['oz_price_calc_clients'])) {
                $limits['oz_price_calc_clients']++;
                SubscribersSubscriptions::where([
                    'subscribers_id' => $subscriber->id,
                ])->update([
                    'limits_plan' => $limits,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет удалён'],
        ], 200);
    }
}
