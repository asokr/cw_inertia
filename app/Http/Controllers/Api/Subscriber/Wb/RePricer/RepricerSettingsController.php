<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\RePricer;

use Illuminate\Http\Request;
use App\Http\Traits\WBadvTrait;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;

class RepricerSettingsController extends Controller
{
    use SubscriptionsTrait;
    use WBadvTrait;

    public function show(string $id)
    {

        $cabinet = RepricerSettings::where('cabinet_id', $id)->first();

        if ($cabinet && !$cabinet->belong())
            return response()->json(["success" => false, "messages" => ["Не ваш кабинет"]], 200);

        $model = RepricerSettings::where('cabinet_id', $id)->orderByDesc('id')->get();
        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Список номенклатур"], "data" => $model], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => '',
                'cabinet_id' => 'required|exists:wb_repricer_cabinets,id',
                'nmID' => 'required|integer|unique:wb_repricer_settings,nmID',
                'price_type' => 'required',
                'strategy' => 'required',
                'pricing_modifier_type' => 'required',
                'terms' => 'required|array|min:1',
                'terms.*.start' => 'required|date_format:H:i',
                'terms.*.end'   => 'required|date_format:H:i',
                'terms.*.value' => 'required|numeric',
                'status' => 'required|boolean',
            ],
            [
                'nmID.unique' => 'Такая номенклатура уже есть в системе',
                'terms.start.date_format' => 'Не верный формат веремени. Пример: 11:25',
                'terms.end.date_format' => 'Не верный формат веремени. Пример: 11:25',
            ]
        );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->has('terms')) {
            $overlapError = $this->validateNoOverlap($request->terms);
            if ($overlapError) {
                return response()->json([
                    "success" => false,
                    "messages" => [$overlapError]
                ], 200);
            }
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);

        $subscriber = auth()->user()->subscriber;

        // Проверим лимит подписчика
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id,
            'status' => 1
        ])->first();

        $limits = $userSubscriptions->limits_plan;

        if (isset($limits['repricer_nmid'])) {
            // Если лимит кончился
            if ((int) $limits['repricer_nmid'] == 0) {
                return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество кабинетов."]], 200);
            }
        }

        $model = RepricerSettings::create([
            'name' => $request->name,
            'cabinet_id' => $request->cabinet_id,
            'nmID' => $request->nmID,
            'price_type' => $request->price_type,
            'strategy' => $request->strategy,
            'pricing_modifier_type' => $request->pricing_modifier_type,
            'terms' => $request->terms,
            'status' => $request->status,
        ]);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить номенклатуру"]], 200);
        }

        if (isset($limits['repricer_nmid'])) {
            // Отнимем лимит
            $limits['repricer_nmid']--;
            SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
            ])
                ->update([
                    'limits_plan' => $limits
                ]);
        }

        return response()->json(["success" => true, "messages" => ["Номенклатура добавлена"], "data" => $model], 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => '',
            'nmID' => 'required|integer|unique:wb_repricer_settings,nmID,' . $id,
            'price_type' => 'required',
            'strategy' => 'required',
            'pricing_modifier_type' => 'required',
            'terms' => 'required|array|min:1',
            'terms.*.start' => 'required|date_format:H:i',
            'terms.*.end'   => 'required|date_format:H:i',
            'terms.*.value' => 'required|numeric',
            'status' => 'required|boolean',
        ], [
            'nmID.unique' => 'Такая номенклатура уже есть в системе',
            'terms.*.start.date_format' => 'Не верный формат веремени. Пример: 11:25',
            'terms.*.end.date_format' => 'Не верный формат веремени. Пример: 11:25',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->has('terms')) {
            $overlapError = $this->validateNoOverlap($request->terms);
            if ($overlapError) {
                return response()->json([
                    "success" => false,
                    "messages" => [$overlapError]
                ], 200);
            }
        }

        $model = RepricerSettings::find($id);
        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if (!$model->belong($id))
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);

        $model->name = $request->name;
        $model->nmID = $request->nmID;
        $model->price_type = $request->price_type;
        $model->strategy = $request->strategy;
        $model->pricing_modifier_type = $request->pricing_modifier_type;
        $model->terms = $request->terms;
        $model->status = $request->status;
        if ($request->status) {
            $model->repeats_counter = 0;
        }
        $model->save();

        return response()->json(["success" => true, "messages" => ["Настройки обновлены"], "data" => $model], 200);
    }

    public function destroy(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = RepricerSettings::find($id);
        if (!$model)
            return response()->json(["success" => false, "messages" => ["Такой номенклатуры нет"]], 200);

        if (!$model->belong($id))
            return response()->json(["success" => false, "messages" => ["Это не ваше"]], 200);


        $model->delete();

        $subscriber_id = auth()->user()->subscriber->id;

        $this->syncLimits($subscriber_id, 'repricer_nmid');

        return response()->json(["success" => true, "messages" => ["Номенклатура удалена"]], 200);
    }

    public function getDataFromWb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required',
        ], [
            'cabinet_id.required' => 'Не достаточно данных'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);

        $subscriber = auth()->user()->subscriber;

        // Проверим лимит подписчика
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id,
            'status' => 1
        ])->first();

        $limits = $userSubscriptions->limits_plan;

        if (isset($limits['repricer_nmid'])) {
            // Если лимит кончился
            if ((int) $limits['repricer_nmid'] == 0) {
                return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество номенклатур."]], 200);
            }
        }

        $cards = $this->getnmIds($cabinet->apikey);

        if (isset($cards['code'])) {
            if ($cards['code'] == 401) {
                return  response()->json(["success" => false, "messages" => ["Не верный ключ API"]], 200);
            }
            if ($cards['code'] != 200) {
                return  response()->json(["success" => false, "messages" => ["Ошибка при получении данных"]], 200);
            }
        }

        $data = [];
        foreach ($cards as $item) {
            $data[] = [
                'name' => $item["vendorCode"],
                'cabinet_id' => $cabinet->id,
                'nmID' => $item['nmID'],
                'base_value' => $item["sizes"][0]["price"],
                'base_discount' => $item["discount"],
                'price_type' => 'PRICE',
                'pricing_modifier_type' => 'FIXED',
                'strategy' => 'TIME',
                'terms' => [
                    'start' => '00:00',
                    'end' => '00:00',
                    'value' => 0
                ],
                'active' => 0,
                'status' => 0
            ];
        }

        $params = [
            "settings" => [
                "cursor" => [
                    "limit" => 100
                ],
                "filter" => [
                    "withPhoto" => -1
                ]
            ]
        ];

        $cards = $this->getAllCards($cabinet->apikey, $params);

        if (isset($cards['code'])) {
            if ($cards['code'] == 401) {
                return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с API ключом. Проверьте ключ и разрешения для него."]], 200);
            }

            return response()->json(["success" => false, "messages" => ["Не удалось получить дополнительную информацию"]], 200);
        }

        foreach ($data as $i => $item) {
            foreach ($cards as $value) {
                if ($value["nmID"] == $item['nmID']) {
                    $data[$i]['name'] = $value["vendorCode"];
                }
            }
        }

        if (isset($limits['repricer_nmid'])) {
            // Проверим, хватит ли лимитов для массового добавления номенклатур
            // Если номенклатур больше лимита, обрежем номенклатуры по лимиту
            // if (count($data) >= $limits['repricer_nmid']) {
            //     $data = array_slice($data, 0, $limits['repricer_nmid']);
            // }
        }

        foreach ($data as $nm) {
            RepricerSettings::firstOrCreate(
                ['nmID' => $nm['nmID']],
                $nm
            );
        }


        if (isset($limits['repricer_nmid'])) {
            // Синхронизируем лимиты (добавленное сверх лимита - будет удалено)
            $this->syncLimits($subscriber->id, 'repricer_nmid');
        }

        $model = RepricerSettings::where('cabinet_id', $cabinet->id)->orderByDesc('id')->get();

        return response()->json(["success" => true, "messages" => ["Номенклатура получена"], "data" => $model], 200);
    }

    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required',
            'data' => 'required|array',
            'data.*.nmID' => 'required|integer',
            'data.*.base_value' => 'required|integer',
            'data.*.price_type' => 'required',
            'data.*.strategy' => 'required',
            'data.*.pricing_modifier_type' => 'required',
            'data.*.terms' => 'required|array',
            'data.*.status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);

        foreach ($request->data as $nm) {
            $model = RepricerSettings::where(['id' => $nm['id'], 'nmID' => $nm['nmID']])->first();
            if ($model && !$model->active) {
                $model->price_type = $nm['price_type'];
                $model->strategy = $nm['strategy'];
                $model->pricing_modifier_type = $nm['pricing_modifier_type'];
                $model->terms = $nm['terms'];
                $model->status = $nm['status'];
                $model->save();
            }
        }

        return response()->json(["success" => true, "messages" => ["Даннные обновлены"]], 200);
    }

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required',
            'ids' => 'required|array',
            'ids.*' => 'required',
        ], [
            'cabinet_id.required' => 'Не достаточно данных',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        foreach ($request->ids as $id) {
            $model = RepricerSettings::find($id);
            if (!$model && !$model->belong($id))
                continue;

            $model->delete();
        }

        $subscriber_id = auth()->user()->subscriber->id;

        $this->syncLimits($subscriber_id, 'repricer_nmid');

        return response()->json(["success" => true, "messages" => ["Номенклатуры удалены"]], 200);
    }

    private function getnmIds($apikey, $cards = [], $attempt = 0)
    {

        $offset = 1000 * $attempt;
        $params = [
            'limit' => 1000,
            'offset' => $offset
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($apikey, $params));

        if (isset($response['code'])) {
            if ($response['code'] == 401) {
                return ["code" => 401];
            }
            if ($response['code'] != 200) {
                return ["code" => $response['code']];
            }
        }

        $cards = array_merge($cards, $response['data']['data']["listGoods"]);

        if (count($response['data']['data']["listGoods"]) > 999) {
            sleep(1);
            $cards = $this->getnmIds($apikey, $cards, ++$attempt);
        }

        return $cards;
    }

    protected function validateNoOverlap($terms)
    {
        $count = count($terms);
        // Переводим в интервалы (массивов), поддерживающие переход через полночь
        $expand = function ($start, $end) {
            $s = strtotime($start);
            $e = strtotime($end);
            if ($e > $s) {
                return [[$s, $e]];
            } else {
                // через полночь: [s, 24:00), [00:00, e)
                return [[$s, strtotime('24:00')], [strtotime('00:00'), $e]];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $a = $terms[$i];
            $aRanges = $expand($a['start'], $a['end']);
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $terms[$j];
                $bRanges = $expand($b['start'], $b['end']);
                foreach ($aRanges as [$as, $ae]) {
                    foreach ($bRanges as [$bs, $be]) {
                        if ($as < $be && $bs < $ae) {
                            return "Периоды #" . ($i + 1) . " и #" . ($j + 1) . " пересекаются";
                        }
                    }
                }
            }
        }
        return null;
    }
}
