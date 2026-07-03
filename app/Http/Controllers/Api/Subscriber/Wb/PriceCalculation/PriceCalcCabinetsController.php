<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\PriceCalculation;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Settings;

class PriceCalcCabinetsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_id = Auth::id();
        $clients = PriceCalculationCabinets::where('user_id', $user_id)->orderByDesc('id')->get();
        if (!$clients) {
            return response()->json(["success" => false, "messages" => ["Кабинетов нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Список кабинетов"], "data" => $clients], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'apikey' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        // Проверим, есть ли уже такой кабинет по apikey
        // $clients = PriceCalculationCabinets::all();
        // $alreadyRegistered = false;
        // foreach ($clients as $client) {
        //     if ($request->apikey == $client->apikey) {
        //         $alreadyRegistered = true;
        //         break;
        //     }
        // }
        // if ($alreadyRegistered) {
        //     return response()->json(["success" => false, "messages" => ["Кабинет с таким Api ключом уже есть в системе."]], 200);
        // }

        // Проверим, авторизуется ли API ключ.
        // $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($request->apikey));
        // if (!$checkApiKey['success']) {
        //     if ($checkApiKey['code'] == 401) {
        //         return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с указанным API ключом. Проверьте ключ."]], 200);
        //     }
        // }

        $subscriber = auth()->user()->subscriber;

        // Проверим лимит подписчика
        // Этот инструмент работает и без подписки
        // делаем выборку не обращая внимания на активность подписки
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id
        ])->first();

        $limits = $userSubscriptions->limits_plan;

        if (isset($limits['price_calc_clients'])) {
            // Если лимит кончился
            if ((int) $limits['price_calc_clients'] == 0) {
                return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество кабинетов."]], 200);
            } else {
                // Отнимем лимит
                $limits['price_calc_clients']--;
                SubscribersSubscriptions::where([
                    'subscribers_id' => $subscriber->id,
                ])
                    ->update([
                        'limits_plan' => $limits
                    ]);
            }

        }

        $client = PriceCalculationCabinets::create([
            "user_id" => auth()->user()->id,
            "name" => $request->name,
            "apikey" => $request->apikey,
        ]);

        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить кабинет"]], 200);
        }

        PriceCalculationV2Settings::firstOrCreate(
            ['cabinet_id' => $client->id],
            ['hide_sizes' => true]
        );

        return response()->json(["success" => true, "messages" => ["Кабинет добавлен"], "data" => $client], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:wb_price_cabinets,id'
        ], [
            'id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = PriceCalculationCabinets::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $client->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет получен"], "data" => $client], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required",
            "apikey" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = PriceCalculationCabinets::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $client->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Проверим, есть ли уже такой кабинет по apikey
        // $clients = PriceCalculationCabinets::all();
        // $alreadyRegistered = false;
        // foreach ($clients as $item) {
        //     if ($id == $item->id)
        //         continue;
        //     if ($request->apikey == $item->apikey) {
        //         $alreadyRegistered = true;
        //         break;
        //     }
        // }
        // if ($alreadyRegistered) {
        //     return response()->json(["success" => false, "messages" => ["Кабинет с таким Api ключом уже есть в системе."]], 200);
        // }

        // Проверим, авторизуется ли API ключ.
        // $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($request->apikey));
        // if (!$checkApiKey['success'] && $checkApiKey['code'] == 401) {
        //     return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с указанным API ключом. Проверьте ключ."]], 200);
        // }

        $client->name = $request->name;
        $client->apikey = $request->apikey;
        $client->save();

        return response()->json(["success" => true, "messages" => ["Кабинет обновлён"], "data" => $client], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = PriceCalculationCabinets::find($id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $subscriber = auth()->user()->subscriber;

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $client->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $client->delete();

        // Актуализируем лимит
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id
        ])->first();

        $limits = $userSubscriptions->limits_plan;

        if (isset($limits['price_calc_clients'])) {
            // добавим лимит
            $limits['price_calc_clients']++;
            SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
            ])
                ->update([
                    'limits_plan' => $limits
                ]);
        }


        return response()->json(["success" => true, "messages" => ["Кабинет удалён"]], 200);
    }
}
