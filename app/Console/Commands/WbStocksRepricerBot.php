<?php

namespace App\Console\Commands;

use App\Http\Traits\WBadvTrait;
use App\Http\Traits\WBApiTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;

class WbStocksRepricerBot extends Command
{

    use WBadvTrait;
    use WBApiTrait;

    protected $log = [
        'cabinet_id' => 0,
        'nmID' => 0,
        'message' => '',
        'type' => 'info',
        'strategy' => 'STOCKS'
    ];

    protected $errors_limit = 10;
    protected $apikey = false;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:wb-stocks-repricer-bot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Бот для репрайсера от остатков';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $subscriber_subscriptions = array();
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

        if (!$subscriptions)
            return false;

        //Соберем подписчиков с нужным нам тарифом
        $subscriber_subscriptions = [];
        foreach ($subscriptions as $subscription) {
            $modelPlan = SubscribersPlans::find($subscription->plan_id);
            if (in_array('subscriber wb repricer', $modelPlan->permissions)) {
                $subscriber_subscriptions[] = $subscription;
            }
        }

        if (!count($subscriber_subscriptions))
            return false;

        // Прогоним бота по каждому подписчику для ответов ИИ
        foreach ($subscriber_subscriptions as $subscription) {
            $user = $subscription->getUser();

            // Все кабинеты подписчика
            $cabinets = RepricerCabinets::where([
                'user_id' => $user->id,
            ])->get();

            if (!$cabinets)
                continue;

            foreach ($cabinets as $cabinet) {
                $this->line("Кабинет {$cabinet->id}: начинаем обработку номенклатур по остаткам...");
                $this->log['cabinet_id'] = $cabinet->id;
                $this->apikey = $cabinet->apikey;

                $stockModel = RepricerStocks::where([
                    'cabinet_id' => $cabinet->id,
                    'status' => 1
                ])->get();

                if (!$stockModel)
                    continue;

                foreach ($stockModel as $item) {
                    $nmID = (int) $item['nmID'];
                    $this->line("Обработка номенклатуры {$nmID}");

                    $this->log['nmID'] = $nmID;

                    $data['data'] = [];
                    $data['data'][0]['nmID'] = $nmID;



                    if (!$item->active) {
                        // Если стратегия не активна, значит мы будем входить в неё первый раз.
                        // Запомним стоимость товара до входа в стратегию.
                        $base_values = $this->setBaseValues($nmID);

                        // Если не удалось получить цену до скидки, выйдем
                        if (!$base_values)
                            continue;
                    }

                    // Стратегия смены номенклатуры
                    // 1 - Для всей номенклатуры
                    // 2 - Для размеров
                    if ($item->strategy == 1) {
                        // Получим остатки
                        $stocks = $this->getStocks($nmID, 1);
                        if (!$stocks)
                            continue;

                        sleep(20); //Превышен лимит запросов. (Максимум 1 запрос за 30 секунд для метода Остатки по всей номенклатуре на один аккаунт продавца)
                        $this->line("Обработка номенклатуры общих остатков: " . $stocks['stocks']);

                        // Посчитаем общие остатки в $nm_stocks
                        $nm_stocks = 0;
                        if (isset($stocks['stocks'])) {
                            $nm_stocks = $stocks['stocks'];
                        }

                        $add_value = 0;
                        foreach ($item->terms['data'] as $term) {
                            if ($term['from'] >= $nm_stocks) {
                                // при добавлении и редактировании мы сортируем
                                // данные от меньшего к большему.
                                // поэтому первый вход по условию в отсортированном массиве
                                // будет нужным нам условием
                                if ($term['is_procent']) {
                                    // если процент, то посчитаем конечную стоимость
                                    $add_value = $item->base_value / 100 * $term['add_to_price'];
                                } else {
                                    $add_value = $term['add_to_price'];
                                }

                                break;
                            }
                        }

                        // Если add_value нет, то значит мы не попадаем в стратегию,
                        // нужно выходить.
                        if (!$add_value) {
                            $this->line("Номенклатура не попадает в условия стратегии. Выходим из стратегии.");
                            $this->removeFromStrategy($item, $data);
                            continue;
                        }
                    } else if ($item->strategy == 2) {
                        sleep(60); //Превышен лимит запросов. (Максимум 1 запрос за минуту для метода Остатки по размерам на один аккаунт продавца)
                        // Получим остатки
                        $stocks = $this->getStocks($nmID, 2);
                        if (!$stocks)
                            continue;

                        $this->line("Обработка номенклатуры остатков по размерам: " . implode(', ', array_map(
                            fn($size, $qty) => "$size: $qty",
                            array_keys($stocks['sizes']),
                            $stocks['sizes']
                        )));

                        $actual_terms = $this->getActualTerms($item, $stocks['sizes']);
                        $this->line("Стратегия 2. Актуальные условия для номенклатуры: " . json_encode($actual_terms));
                        // Если $actual_terms пустой массив, значит номенклатуры не должно быть в стратегии.
                        // уберём её оттуда
                        if (empty($actual_terms)) {
                            $this->removeFromStrategy($item, $data);
                            continue;
                        }

                        $add_value = $this->getAddValue($actual_terms, $item->base_value);
                        if (!$add_value)
                            continue;
                    }
                    $this->line("Добавляем к цене значение: " . $add_value . " р.");
                    // Если такая же сумма и была прибавлена к номенклатуре, выходим
                    if (isset($item->added_value) && $item->added_value == $add_value) {
                        continue;
                    }

                    // Посчитаем, сколько нам нужно добавить, чтобы цена увеличилась на нужную стоимость

                    // Старая цена со скидкой
                    // $price_with_discount = $base_values['base_value'] - ($base_values['base_value'] / 100 * $base_values['base_discount']);

                    // Цена со скидкой должна составлять 100% - 58% = 42% (0.42) от новой базовой цены:
                    $product_price_discount_value = (100 - $item->base_discount) / 100;

                    // Новая цена со скидкой
                    // $new_price_with_discount = $price_with_discount + $add_value;

                    $add_value_with_discount = $add_value / $product_price_discount_value;


                    // Добавим скидку к добавленной стоимости
                    // $add_value_with_discount = $add_value + ($add_value / 100 * $base_values['base_discount']);

                    $new_price = round($item->base_value + $add_value_with_discount);
                    $this->line("Рассчитана новая цена: " . $new_price . " р.");
                    $data['data'][0]['price'] = $new_price;

                    $price_change = $this->setPrice($data, $item);

                    if ($price_change) {
                        $this->line("По условиям репрайсера зашли в стратегию с ценой до скидки " . $new_price . " р.");
                        $item->active = 1;
                        $item->added_value = $add_value;

                        $this->log['message'] = 'Вход в стратегию. Стоимость товара до скидки - ' . $new_price . ' р.';
                        $this->log['type'] = 'success';
                    }

                    // Счётчик считает кол-во попыток работать с одной номенклатурой.
                    // Не его основе мы отключаем работу после соотвествующего числа повторений
                    $item->repeats_counter++;


                    if ($this->log['type'] == 'success') {
                        $item->repeats_counter = 0;
                    }

                    $item->save();

                    if ($this->log['message'] != '')
                        RepricerLogs::create($this->log);
                }
            }
        }
    }

    private function getStocks($nmID, $strategy)
    {

        $sizes = [];
        $resp = $this->parseApiResponse($this->apiGetStockDataBySize($this->apikey));
        if (!$resp['success']) {
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось получить остатки.';
            $this->log['type'] = 'error';
            Log::channel('wb_api_response')->info('STOCKS: Текст ошибки: ' . $resp['data']['errorText'] . ' Код ошибки: ' . $resp['code']);
            // Обработаем ошибки
            if ($resp['code'] == 400) {
                if ($resp['data']['error']) {
                    Log::channel('wb_api_response')->info('STOCKS: Текст ошибки: ' . $resp['data']['errorText']);
                }
            }

            // Если кабинет не авторизован, отключим все номенклатуры
            if ($resp['code'] == 401) {
                RepricerSettings::where([
                    'cabinet_id' => $this->log['cabinet_id'],
                ])->update(['status' => 0]);
                $this->log['message'] = 'Не верный ключ API. Номенклатуры кабинета отключены. Проверьте API ключ.';
            }

            return false;
        }

        $cards = isset($resp['data']) ? $resp['data'] : null;
        unset($resp);
        if (!$cards) {
            return response()->json(["success" => false, "messages" => ["Ошибка при получении остатков по размерам"]], 200);
        }
        $sizes = [];
        foreach ($cards as $item) {
            if ($item['nmId'] == $nmID) {
                isset($sizes[$item['techSize']]) ? null : $sizes[$item['techSize']] = 0;
                $sizes[$item['techSize']] += (int) ($item['quantity'] ?? 0);
            }
        }

        $data['sizes'] = $sizes;
        $data['stocks'] = array_sum($sizes);
        return $data;
    }

    private function getActualTerms($settings, $stocks)
    {
        $actual_terms = [];

        $terms = $settings->terms;
        foreach ($stocks as $size => $qty) {
            $actual_term = false;
            foreach ($terms as $key => $term) {
                $this->line("Сравниваем размеры " . $size . " с размеров в базе " . $term['size']);
                if ($size == $term['size']) {
                    $terms[$key]['qty'] = $qty;
                    $this->line("Обрабатываем размер " . $term['size'] . " с количеством " . $qty);
                    foreach ($term['values'] as $value) {
                        if ($qty <= $value['from']) {
                            if (!$actual_term) {
                                $actual_term = $value;
                            } else if ($value['from'] <= $actual_term['from']) {
                                $actual_term = $value;
                            }
                        }
                    }
                }
            }
            if ($actual_term) {
                $this->line("Условие, по которму будем работать " . $actual_term['from'] . " добавим к цене " . $actual_term['add_to_price'] . " р.");
                $actual_terms[] = $actual_term;
            }
        }

        $settings->terms = $terms;
        $settings->save();

        return $actual_terms;
    }

    private function removeFromStrategy($item, $data)
    {
        // Если стратегия была активной и нам нужно убрать номенклатуру из стратегии
        //
        if ($item->active) {
            $data['data'][0]['price'] = (int) $item->base_value;
            $price_change = $this->setPrice($data, $item);
            if ($price_change) {
                $item->active = 0;
                $item->added_value = 0;
                $item->repeats_counter = 0;
                $item->save();
                $this->log['message'] = 'Выход из стратегии. Стоимость товара до скидки - ' . $item->base_value . ' р.';
                $this->log['type'] = 'success';
                RepricerLogs::create($this->log);
            } else {
                sleep(2);
                $item->repeats_counter += 1;
                $item->save();
                $this->removeFromStrategy($item, $data);
            }
        }
    }

    private function getAddValue($actual_terms, $base_value)
    {
        $add_to_price = 0;
        foreach ($actual_terms as $actual_term) {
            if ($actual_term['add_to_price'] >= $add_to_price) {
                if ($actual_term['is_procent']) {
                    // если процент, то посчитаем конечную стоимость
                    $add_to_price = $base_value / 100 * $actual_term['add_to_price'];
                } else {
                    $add_to_price = $actual_term['add_to_price'];
                }
            }
        }

        return $add_to_price;
    }


    private function setPrice($data, $item)
    {
        Log::channel('wb_api_response')->info('STOCKS: ' . json_encode($data));
        sleep(1); //Превышен лимит запросов. (Максимум 10 запросов за 6 секунд для всех методов категории Цены и скидки на один аккаунт продавца)
        $resp = $this->parseApiResponse($this->apiSetPrice($this->apikey, $data));

        if (!$resp['success']) {
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 1.';
            $this->log['type'] = 'error';

            // Обработаем ошибки
            if ($resp['code'] == 400) {
                if ($resp['data']['error']) {
                    $errorText = $resp['data']['errorText'] ?? '';
                    // Если ошибка "No goods for process" - предположим, что у нас уже стоит верная цена, и мы, пытаемся поставить такую-же.
                    Log::channel('wb_api_response')->info('STOCKS: Текст ошибки: ' . $errorText);

                    if (stripos($errorText, 'already set') !== false) {
                        $this->log['message'] = 'Устанавливаемая цена (скидка) уже применена на WB или карточка не имеет цены. Отключаем работу с номенклатурой.';
                        $item->active = 0;
                        $item->status = 0;
                        $item->save();
                    }

                    // Если мы много раз пытаемся войти/выйти из стратегии, вероятно, какая-то ошибка.
                    // Отключим номенклатуру.
                    if ($item->repeats_counter >= $this->errors_limit) {
                        $this->log['message'] = 'Останавливаем работу с номенклатурой после превышения лимита ошибок. Проверьте настройки номенклатуры. Проверьте цену/скидку номенклатуры на WildBerries.';
                        $item->active = 0;
                        $item->status = 0;
                        $item->save();
                    }
                    $item->save();
                }
            }

            // Если кабинет не авторизован, отключим все номенклатуры
            if ($resp['code'] == 401) {
                RepricerSettings::where([
                    'cabinet_id' => $this->log['cabinet_id'],
                ])->update(['status' => 0]);
                $this->log['message'] = 'Не верный ключ API. Номенклатуры кабинета отключены. Проверьте API ключ.';
            }

            return false;
        }
        if (!isset($resp['data']['data']['id'])) {
            return false;
        }

        sleep(1);
        $price_change = $this->checkPriceChange($resp['data']['data']['id']);

        if (!$price_change) {
            sleep(1);
            $price_change = $this->checkPriceChange($resp['data']['data']['id']);
            if (!$price_change) {
                return false;
            }
        }

        return true;
    }

    /*
        integer (TaskStatus)
        Статус загрузки:

        3 — обработана, в товарах нет ошибок, цены и скидки обновились
        4 — отменена
        5 — обработана, но в товарах есть ошибки. Для товаров без ошибок цены и скидки обновились, а ошибки в остальных товарах можно получить с помощью метода Детализация обработанной загрузки
        6 — обработана, но во всех товарах есть ошибки. Их тоже можно получить с помощью метода Детализация обработанной загрузки
    */
    private function checkPriceChange($uploadID)
    {
        $resp = $this->parseApiResponse($this->apiGetPriceChangeStatus($this->apikey, $uploadID));

        if (!$resp['success']) {
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 2.';
            $this->log['type'] = 'error';
            return false;
        }

        if (isset($resp['data']['data']['status'])) {
            $status = $resp['data']['data']['status'];
        } else {
            // Тут была замечена проблема, что $resp['data']['data']['status'] нет, но код 200 и цена изменена.
            // поэтому, если тут код 200, попробуем проверить ещё раз статус выгрузки
            if ($resp['code'] == 200) {
                sleep(1);
                return $this->checkPriceChange($uploadID);
            }
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 3.';
            $this->log['type'] = 'error';
            return false;
        }


        if ($status != 3) {
            switch ($status) {
                case 4:
                    $error_text = 'отменена';
                    break;
                case 5:
                    $error_text = 'обработана, но в товарах есть ошибки';
                    break;
                case 6:
                    $error_text = 'обработана, но во всех товарах есть ошибки';
                    break;
            }

            $this->log['message'] = 'Ошибка в товаре. Текст ответа WB: ' . $error_text;
            $this->log['type'] = 'error';
            return false;
        }

        return true;
    }


    private function setBaseValues($nmID)
    {

        $params = [
            'limit' => 1,
            'filterNmID' => $nmID
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($this->apikey, $params));


        if (isset($base_values['code'])) {
            $this->log['nmID'] = $nmID;
            $this->log['type'] = 'error';
            if ($base_values['code'] == 401) {
                $this->log['message'] = 'Не верный ключ API';
            }
            if ($base_values['code'] != 200) {
                $this->log['message'] = 'Не удалось получить текущие цены и скидки';
            }
            return false;
        }

        if (!isset($response['data']['data']["listGoods"][0])) {
            return false;
        }
        $card = $response['data']['data']["listGoods"][0];

        $values = [
            'base_value' => $card["sizes"][0]["price"],
            'base_discount' => $card["discount"]
        ];

        RepricerStocks::where('nmID', $nmID)->update($values);

        return $values;
    }
}
