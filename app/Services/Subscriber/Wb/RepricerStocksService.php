<?php

namespace App\Services\Subscriber\Wb;

use Illuminate\Http\Request;
use App\Http\Traits\WBadvTrait;
use App\Http\Traits\WBApiTrait;
use Illuminate\Support\Facades\Log;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;

class RepricerStocksService
{
    use SubscriptionsTrait;
    use WBadvTrait;
    use WBApiTrait;

    public function show(string $id)
    {

        $cabinet = RepricerStocks::where('cabinet_id', $id)->first();

        if ($cabinet && !$cabinet->belong())
            return response()->json(["success" => false, "messages" => ["Не ваш кабинет"]], 200);

        $model = RepricerStocks::where('cabinet_id', $id)->orderByDesc('id')->get();
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
                'nmID' => 'required|integer|unique:wb_repricer_stocks,nmID',
                'strategy' => 'required|integer',
                'terms' => 'required|array',
                'status' => 'required|boolean',
            ],
            [
                'strategy.required' => 'Настройте стратегию перед сохранением',
                'terms.required' => 'Настройте стратегию перед сохранением',
                'required' => 'Все данные обязательны для заполнения',
                'nmID.unique' => 'Такая номенклатура уже есть в системе'
            ]
        );

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

        if (isset($limits['repricer_nmid']) && ! ToolLimits::canUsePlanLimit(auth()->user(), $limits, 'repricer_nmid')) {
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество кабинетов."]], 200);
        }

        $terms = $request->terms;
        $strategy = $request->strategy;

        $terms = $this->attachChrtIdsToTerms($terms, $strategy, (int) $request->nmID);

        // Отсортируем красиво
        // Размеры есть только в стратегии по размерам (2)
        if ($strategy == 1) {
            usort($terms['data'], function ($a, $b) {
                return $a['from'] > $b['from'];
            });
        } else if ($strategy == 2) {
            usort($terms, function ($a, $b) {
                return $a['size'] > $b['size'];
            });


            foreach ($terms as $key => $term) {
                usort($term['values'], function ($a, $b) {
                    return $a['from'] > $b['from'];
                });
                $terms[$key] = $term;
            }
        }

        $model = RepricerStocks::create([
            'name' => $request->name,
            'cabinet_id' => $request->cabinet_id,
            'nmID' => $request->nmID,
            'base_value' => $request->base_value,
            'base_discount' => $request->base_discount,
            'strategy' => $strategy,
            'terms' => $terms,
            'status' => $request->status,
        ]);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить номенклатуру"]], 200);
        }

        $updatedLimits = isset($limits['repricer_nmid'])
            ? ToolLimits::applyPlanLimitConsumption(auth()->user(), $limits, 'repricer_nmid')
            : null;

        if ($updatedLimits !== null) {
            SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
            ])->update([
                'limits_plan' => $updatedLimits,
            ]);
        }

        return response()->json(["success" => true, "messages" => ["Номенклатура добавлена"], "data" => $model], 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => '',
            'nmID' => 'required|integer|unique:wb_repricer_stocks,nmID,' . $id,
            'strategy' => 'required|integer',
            'terms' => 'required|array',
            'status' => 'required|boolean',
        ], [
            'strategy.required' => 'Настройте стратегию перед сохранением',
            'terms.required' => 'Настройте стратегию перед сохранением',
            'nmID.unique' => 'Такая номенклатура уже есть в системе'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = RepricerStocks::find($id);
        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if (!$model->belong($id))
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);

        $terms = $request->terms;
        $strategy = $request->strategy;

        $terms = $this->attachChrtIdsToTerms($terms, $strategy, (int) $request->nmID);

        // Отсортируем красиво
        // Размеры есть только в стратегии по размерам (2)
        if ($strategy == 1) {
            usort($terms['data'], function ($a, $b) {
                return $a['from'] > $b['from'];
            });
        } else if ($strategy == 2) {
            usort($terms, function ($a, $b) {
                return $a['size'] > $b['size'];
            });


            foreach ($terms as $key => $term) {
                usort($term['values'], function ($a, $b) {
                    return $a['from'] > $b['from'];
                });
                $terms[$key] = $term;
            }
        }


        $model->base_value = $request->base_value;

        $model->name = $request->name;
        $model->nmID = $request->nmID;

        $model->strategy = $strategy;

        $model->terms = $terms;
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

        $model = RepricerStocks::find($id);
        if (!$model)
            return response()->json(["success" => false, "messages" => ["Такой номенклатуры нет"]], 200);

        if (!$model->belong($id))
            return response()->json(["success" => false, "messages" => ["Это не ваше"]], 200);


        // if ($model->active && $model->base_value) {
        //     $data = [
        //         'data' => [
        //             0 => [
        //                 'nmID' => $model->nmID,
        //                 'price' => $model->base_value,
        //             ]
        //         ]
        //     ];

        //     $resp = $this->parseApiResponse($this->apiSetPrice($model->cabinet->apikey, $data));

        //     if (!$resp['success']) {
        //         sleep(1); //Нам бы в джобу
        //         $this->parseApiResponse($this->apiSetPrice($model->cabinet->apikey, $data));
        //     }
        // }


        $log = [
            'cabinet_id' => $model->cabinet_id,
            'nmID' => $model->nmID,
            'message' => 'Номенклатура удалена',
            'type' => 'info',
            'strategy' => 'STOCKS'
        ];
        RepricerLogs::create($log);

        $model->delete();

        $subscriber_id = auth()->user()->subscriber->id;

        $this->syncLimits($subscriber_id, 'repricer_nmid');

        return response()->json(["success" => true, "messages" => ["Номенклатура удалена"]], 200);
    }

    public function reset(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $stock = RepricerStocks::find($id);

        if (! $stock) {
            return response()->json(["success" => false, "messages" => ["Такой номенклатуры нет"]], 200);
        }

        if (! $stock->belong()) {
            return response()->json(["success" => false, "messages" => ["Это не ваше"]], 200);
        }

        if ((int) $stock->status !== 0) {
            return response()->json(["success" => false, "messages" => ["Перед сбросом отключите номенклатуру"], "data" => []], 200);
        }

        $stock->active = 0;
        $stock->added_value = 0;
        $stock->repeats_counter = 0;
        $stock->save();

        RepricerLogs::create([
            'cabinet_id' => $stock->cabinet_id,
            'nmID' => $stock->nmID,
            'message' => 'Номенклатура сброшена вручную',
            'type' => 'info',
            'strategy' => 'STOCKS',
        ]);

        return response()->json(["success" => true, "messages" => ["Номенклатура отключена"]], 200);
    }

    public function getDataFromWb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required',
        ], [
            'cabinet_id.required' => 'Не достаточно данных',
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

        if (isset($limits['repricer_nmid']) && ! ToolLimits::canUsePlanLimit(auth()->user(), $limits, 'repricer_nmid')) {
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество номенклатур."]], 200);
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
            $sizes = array();
            foreach ($item["sizes"] as $size) {
                $sizes[] = [
                    'size' => $size['techSizeName'],
                    'from' => 0,
                    'add_to_price' => 0,
                ];
            }
            $data[] = [
                'name' => $item["vendorCode"],
                'cabinet_id' => $cabinet->id,
                'nmID' => $item['nmID'],
                'base_value' => $item["sizes"][0]["price"],
                'terms' => $sizes,
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

        foreach ($data as $nm) {
            RepricerStocks::firstOrCreate(
                ['nmID' => $nm['nmID']],
                $nm
            );
        }

        if (isset($limits['repricer_nmid'])) {
            // Синхронизируем лимиты (добавленное сверх лимита - будет удалено)
            $this->syncLimits($subscriber->id, 'repricer_nmid');
        }

        $model = RepricerStocks::where('cabinet_id', $cabinet->id)->orderByDesc('id')->get();

        return response()->json(["success" => true, "messages" => ["Номенклатура получена"], "data" => $model], 200);
    }

    public function getSizesFromWb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required',
            'nmID' => 'required',
            'sizes' => 'sometimes|boolean',
        ], [
            'cabinet_id.required' => 'Не достаточно данных',
            'nmID.required' => 'Не достаточно данных',
            'sizes.boolean' => 'Некорректный формат данных',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);

        if (! $cabinet) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);
        }

        $belongs = $cabinet->user_id == auth()->user()->id;
        if (! $belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);
        }

        $withSizes = (bool) $request->sizes;

        $data = [];

        $priceResponse = $this->parseApiResponse($this->apiGetPrices($cabinet->apikey, [
            'filterNmID' => $request->nmID,
            'limit' => 5,
        ]));

        if (isset($priceResponse['code'])) {
            if ($priceResponse['code'] == 401) {
                return response()->json(["success" => false, "messages" => ["Не верный ключ API"]], 200);
            }

            if ($priceResponse['code'] != 200) {
                return response()->json(["success" => false, "messages" => ["Ошибка при получении данных"]], 200);
            }
        }

        $productData = $priceResponse['data']['data']['listGoods'][0] ?? null;

        if (! $productData) {
            return response()->json(["success" => false, "messages" => ["Ошибка при получении данных"]], 200);
        }

        $chrtMap = $this->getChrtIdsBySize($request->nmID);

        $data['editableSizePrice'] = $productData['editableSizePrice'];

        if (! $data['editableSizePrice']) {
            $basePrice = $productData['sizes'][0]['price'] ?? null;
            $baseDiscount = $productData['discount'] ?? null;

            if ($basePrice !== null) {
                $data['price'] = $basePrice;
            }

            if ($baseDiscount !== null) {
                $data['discount'] = $baseDiscount;
            }

            if ($basePrice !== null) {
                $baseModel = RepricerStocks::where(['nmID' => $request->nmID])->first();

                if ($baseModel) {
                    $baseModel->base_value = $basePrice;
                    $baseModel->save();
                }
            }
        }

        $stocksResponse = $this->parseApiResponse($this->apiGetStockDataBySize($cabinet->apikey, false));

        if (isset($stocksResponse['code'])) {
            if ($stocksResponse['code'] == 401) {
                return response()->json(["success" => false, "messages" => ["Не верный ключ API"]], 200);
            }

            if ($stocksResponse['code'] != 200) {
                return response()->json(["success" => false, "messages" => ["Ошибка при получении данных"]], 200);
            }
        }

        $items = $stocksResponse['data'] ?? [];

        if (empty($items)) {
            return response()->json(["success" => false, "messages" => ["Ошибка при получении остатков"]], 200);
        }

        $wbTotals = 0;
        $wbSizes = [];
        $wbSizeMap = [];

        foreach ($items as $item) {
            if ((string) ($item['nmId'] ?? '') !== (string) $request->nmID) {
                continue;
            }

            $qty = (int) ($item['quantity'] ?? 0);
            $wbTotals += $qty;

            $sizeKey = (string) ($item['techSize'] ?? '');

            if (! isset($wbSizeMap[$sizeKey])) {
                $wbSizeMap[$sizeKey] = [
                    'qty' => 0,
                    'price' => 0,
                    'chrtId' => $chrtMap[$sizeKey] ?? null,
                ];
            }

            $wbSizeMap[$sizeKey]['qty'] += $qty;

            $priceValue = (int) ($item['Price'] ?? 0);
            if ($wbSizeMap[$sizeKey]['price'] < $priceValue) {
                $wbSizeMap[$sizeKey]['price'] = $priceValue;
            }

            if (! $wbSizeMap[$sizeKey]['chrtId'] && isset($chrtMap[$sizeKey])) {
                $wbSizeMap[$sizeKey]['chrtId'] = $chrtMap[$sizeKey];
            }
        }

        if ($withSizes) {
            if (empty($wbSizeMap)) {
                return response()->json(["success" => false, "messages" => ["Ошибка при получении остатков по размерам"]], 200);
            }

            $wbSizes = $wbSizeMap;
            $data['sizes'] = $wbSizes;
        } else {
            $data['stocks'] = $wbTotals;
        }

        $pureWbSizes = $withSizes ? $wbSizes : $wbSizeMap;

        $stockModel = RepricerStocks::where('cabinet_id', $cabinet->id)
            ->where('nmID', $request->nmID)
            ->first();

        $sellerData = $this->fetchSellerStocksForNm(
            $cabinet,
            $stockModel,
            $pureWbSizes,
            (string) $request->nmID
        );

        if ($withSizes && ! empty($wbSizes)) {
            foreach ($wbSizes as $sizeKey => &$sizeData) {
                $sellerSize = $sellerData['sizes'][$sizeKey] ?? null;

                if (! $sellerSize && isset($sizeData['chrtId'])) {
                    $sellerSize = $sellerData['sizes'][$sizeData['chrtId']] ?? null;
                }

                $sizeData['qty'] += (int) ($sellerSize['qty'] ?? 0);
            }
            unset($sizeData);

            foreach ($sellerData['sizes'] as $sellerKey => $sellerInfo) {
                if (isset($wbSizes[$sellerKey])) {
                    continue;
                }

                $wbSizes[$sellerKey] = [
                    'qty' => (int) ($sellerInfo['qty'] ?? 0),
                    'price' => 0,
                    'chrtId' => $sellerInfo['chrtId'] ?? null,
                ];
            }

            ksort($wbSizes, SORT_NATURAL);

            $data['sizes'] = array_map(static function (array $item): array {
                return [
                    'chrtId' => $item['chrtId'] ?? null,
                    'price' => $item['price'] ?? 0,
                    'qty' => (int) ($item['qty'] ?? 0),
                ];
            }, $wbSizes);
        }

        $data['chrtIds'] = array_values(array_unique(array_filter(array_column($pureWbSizes, 'chrtId'))));

        if ($withSizes) {
            $data['stocks'] = array_sum(array_map(static fn($size) => (int) ($size['qty'] ?? 0), $wbSizes));
        } else {
            $data['stocks'] = $wbTotals + ($sellerData['total'] ?? 0);
        }

        return response()->json(["success" => true, "messages" => ["Размеры получены"], "data" => $data], 200);
    }

    // public function bulkUpdate(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'cabinet_id' => 'required',
    //         'data' => 'required|array',
    //         'data.*.nmID' => 'required|integer',
    //         'data.*.base_value' => 'required|integer',
    //         'data.*.terms' => 'required|array',
    //         'data.*.status' => 'required|boolean',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
    //     }

    //     $cabinet = RepricerCabinets::find($request->cabinet_id);
    //     // Проверим, принадлежит-ли кабинет текущему юзеру
    //     $belongs = $cabinet->user_id == auth()->user()->id;
    //     if (!$belongs)
    //         return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);

    //     foreach ($request->data as $nm) {
    //         $model = RepricerStocks::where(['id' => $nm['id'], 'nmID' => $nm['nmID']])->first();
    //         if ($model && !$model->active) {
    //             $model->terms = $nm['terms'];
    //             $model->status = $nm['status'];
    //             $model->save();
    //         }
    //     }

    //     return response()->json(["success" => true, "messages" => ["Даннные обновлены"]], 200);
    // }

    // public function bulkDestroy(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'cabinet_id' => 'required',
    //         'ids' => 'required|array',
    //         'ids.*' => 'required',
    //     ], [
    //         'cabinet_id.required' => 'Не достаточно данных',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
    //     }

    //     foreach ($request->ids as $id) {
    //         $model = RepricerStocks::find($id);
    //         if (!$model && !$model->belong($id))
    //             continue;

    //         $model->delete();
    //     }

    //     $subscriber_id = auth()->user()->subscriber->id;

    //     $this->syncLimits($subscriber_id, 'repricer_nmid');

    //     return response()->json(["success" => true, "messages" => ["Номенклатуры удалены"]], 200);
    // }

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

    private function fetchSellerStocksForNm(
        RepricerCabinets $cabinet,
        ?RepricerStocks $stock,
        array $wbSizes = [],
        ?string $nmId = null
    ): array {
        $chrtToSize = $stock ? $this->collectStockChrtIds($stock)['chrtToSize'] : [];

        if (empty($chrtToSize) && ! empty($wbSizes)) {
            $chrtToSize = $this->collectChrtIdsFromWbSizes($wbSizes);
        }

        $nmIdValue = $nmId ?? ($stock ? (string) $stock->nmID : null);

        if (empty($chrtToSize)) {
            return [
                'total' => 0,
                'sizes' => [],
                'chrtIds' => [],
            ];
        }

        $warehousesResponse = $this->parseApiResponse($this->apiGetSellerWarehouses($cabinet->apikey));

        if (! ($warehousesResponse['success'] ?? false)) {
            Log::warning('repricer.getSizesFromWb.seller_warehouses_failed', [
                'cabinet_id' => $cabinet->id,
                'nm_id' => $nmIdValue,
                'code' => $warehousesResponse['code'] ?? null,
            ]);

            return [
                'total' => 0,
                'sizes' => [],
                'chrtIds' => array_keys($chrtToSize),
            ];
        }

        $warehouses = $warehousesResponse['data'] ?? [];
        if (! is_array($warehouses) || empty($warehouses)) {
            return [
                'total' => 0,
                'sizes' => [],
                'chrtIds' => array_keys($chrtToSize),
            ];
        }

        $warehouseIds = [];

        foreach ($warehouses as $warehouse) {
            $warehouseId = (int) ($warehouse['id'] ?? 0);

            if ($warehouseId > 0) {
                $warehouseIds[$warehouseId] = $warehouseId;
            }
        }

        if (empty($warehouseIds)) {
            return [
                'total' => 0,
                'sizes' => [],
                'chrtIds' => array_keys($chrtToSize),
            ];
        }

        $result = [
            'total' => 0,
            'sizes' => [],
            'chrtIds' => array_values(array_unique(array_keys($chrtToSize))),
        ];

        $chrtMatches = $chrtToSize;
        $allChrtIds = array_keys($chrtMatches);


        foreach ($warehouseIds as $warehouseId) {
            foreach (array_chunk($allChrtIds, 900) as $chunk) {
                if (empty($chunk)) {
                    continue;
                }

                $stocksResponse = $this->parseApiResponse(
                    $this->apiGetSellerWarehouseStocks($cabinet->apikey, $warehouseId, $chunk)
                );

                if (! ($stocksResponse['success'] ?? false)) {
                    Log::warning('repricer.getSizesFromWb.seller_stock_failed', [
                        'cabinet_id' => $cabinet->id,
                        'nm_id' => $nmIdValue,
                        'warehouse_id' => $warehouseId,
                        'code' => $stocksResponse['code'] ?? null,
                    ]);

                    continue;
                }

                $stocks = $stocksResponse['data']['stocks'] ?? [];

                if (! is_array($stocks) || empty($stocks)) {
                    continue;
                }

                foreach ($stocks as $row) {
                    $chrtIdValue = isset($row['chrtId']) ? trim((string) $row['chrtId']) : '';
                    if ($chrtIdValue === '') {
                        $chrtIdValue = isset($row['sku']) ? trim((string) $row['sku']) : '';
                    }

                    $amount = (int) ($row['amount'] ?? 0);

                    if ($chrtIdValue === '' || $amount <= 0 || ! isset($chrtMatches[$chrtIdValue])) {
                        continue;
                    }

                    $sizeKey = $chrtMatches[$chrtIdValue];
                    $result['total'] += $amount;

                    $key = $sizeKey !== null && $sizeKey !== '' ? $sizeKey : $chrtIdValue;

                    if (! isset($result['sizes'][$key])) {
                        $result['sizes'][$key] = [
                            'qty' => 0,
                            'chrtId' => $chrtIdValue,
                        ];
                    }

                    $result['sizes'][$key]['qty'] += $amount;
                    $result['sizes'][$key]['chrtId'] = $chrtIdValue;
                }
            }
        }

        return $result;
    }

    private function collectStockChrtIds(RepricerStocks $stock): array
    {
        $terms = $stock->terms;
        $chrtToSize = [];

        if (! is_array($terms)) {
            return ['chrtToSize' => $chrtToSize];
        }

        $strategy = (int) $stock->strategy;

        $map = $this->getChrtIdsBySize($stock->nmID);

        if ($strategy === 1) {
            $chrtIds = $terms['chrtIds'] ?? [];

            foreach ((array) $chrtIds as $chrtId) {
                $intId = (int) $chrtId;
                if ($intId <= 0) {
                    continue;
                }

                $chrtToSize[$intId] = null;
            }
        } else {
            foreach ($terms as $term) {
                if (! is_array($term)) {
                    continue;
                }

                $intId = isset($term['chrtId']) ? (int) $term['chrtId'] : 0;
                $size = isset($term['size']) ? trim((string) $term['size']) : '';

                if ($intId <= 0 && $size !== '' && isset($map[$size])) {
                    $intId = (int) $map[$size];
                }

                if ($intId <= 0) {
                    continue;
                }

                $chrtToSize[$intId] = $size !== '' ? $size : null;
            }
        }

        return ['chrtToSize' => $chrtToSize];
    }

    private function collectChrtIdsFromWbSizes(array $wbSizes): array
    {
        $chrtToSize = [];

        foreach ($wbSizes as $sizeKey => $sizeData) {
            $intId = isset($sizeData['chrtId']) ? (int) $sizeData['chrtId'] : 0;

            if ($intId <= 0) {
                continue;
            }

            $chrtToSize[$intId] = (string) $sizeKey;
        }

        return $chrtToSize;
    }

    private function attachChrtIdsToTerms(array $terms, int $strategy, int $nmId): array
    {
        $map = $this->getChrtIdsBySize($nmId);

        if ($strategy === 1) {
            $chrtIds = [];

            foreach ($map as $chrtId) {
                $intId = (int) $chrtId;

                if ($intId > 0) {
                    $chrtIds[] = $intId;
                }
            }

            $terms['chrtIds'] = $chrtIds;

            if (isset($terms['barcodes'])) {
                unset($terms['barcodes']);
            }

            return $terms;
        }

        foreach ($terms as $index => $term) {
            if (! is_array($term)) {
                continue;
            }

            $size = isset($term['size']) ? (string) $term['size'] : '';
            $chrtId = $size !== '' && isset($map[$size]) ? (int) $map[$size] : 0;

            $terms[$index]['chrtId'] = $chrtId > 0 ? $chrtId : null;

            if (isset($terms[$index]['barcode'])) {
                unset($terms[$index]['barcode']);
            }
        }

        return $terms;
    }

    private function getChrtIdsBySize(int|string $nmId): array
    {
        $result = [];

        try {
            $response = $this->productDataApi($nmId);

            if ($response && isset($response['sizes_table']->values) && is_array($response['sizes_table']->values)) {
                foreach ($response['sizes_table']->values as $value) {
                    if (! is_object($value)) {
                        continue;
                    }

                    $techSize = isset($value->tech_size) ? (string) $value->tech_size : '';
                    $chrtId = isset($value->chrt_id) ? (int) $value->chrt_id : 0;

                    if ($techSize === '' || $chrtId <= 0) {
                        continue;
                    }

                    $result[$techSize] = $chrtId;
                }
            }

            if (empty($result)) {
                $rawChrtIds = [];

                if (isset($response['data'])) {
                    $rawData = $response['data'];

                    if (is_object($rawData) && isset($rawData->chrt_ids)) {
                        $rawChrtIds = $rawData->chrt_ids;
                    } elseif (is_array($rawData) && isset($rawData['chrt_ids'])) {
                        $rawChrtIds = $rawData['chrt_ids'];
                    }
                } elseif (isset($response['chrt_ids'])) {
                    $rawChrtIds = $response['chrt_ids'];
                }

                foreach ((array) $rawChrtIds as $chrtId) {
                    $intId = (int) $chrtId;

                    if ($intId > 0) {
                        $result[] = $intId;
                    }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('repricer.chrt_map_failed', [
                'nm_id' => $nmId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $result;
    }
}
