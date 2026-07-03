<?php

namespace App\Console\Commands;

use App\Http\Traits\WBadvTrait;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WbRepricerBot extends Command
{

    use WBadvTrait;

    protected $log = [
        'cabinet_id' => 0,
        'nmID'       => 0,
        'message'    => '',
        'type'       => 'info',
        'strategy'   => 'TIME',
    ];

    protected $active_period = [];

    protected $errors_limit = 10;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:wb-repricer-bot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Бот для репрайсера';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $subscriber_subscriptions = [];
        $subscriptions            = SubscribersSubscriptions::where('status', 1)->get();

        if (! $subscriptions) {
            return false;
        }

        //Соберем подписчиков с нужным нам тарифом
        $subscriber_subscriptions = [];
        foreach ($subscriptions as $subscription) {
            $modelPlan = SubscribersPlans::find($subscription->plan_id);
            if (in_array('subscriber wb repricer', $modelPlan->permissions)) {
                $subscriber_subscriptions[] = $subscription;
            }
        }

        if (! count($subscriber_subscriptions)) {
            return false;
        }

        // Прогоним бота по каждому подписчику для ответов ИИ
        foreach ($subscriber_subscriptions as $subscription) {
            $user = $subscription->getUser();

            // Все кабинеты подписчика
            $cabinets = RepricerCabinets::where([
                'user_id' => $user->id,
            ])->get();

            if (! $cabinets) {
                continue;
            }

            foreach ($cabinets as $cabinet) {
                $this->log['cabinet_id'] = $cabinet->id;
                $apikey                  = $cabinet->apikey;

                $timeModel = RepricerSettings::where([
                    'cabinet_id' => $cabinet->id,
                    'status'     => 1,
                ])->get();

                if (! $timeModel) {
                    continue;
                }

                foreach ($timeModel as $item) {

                    $nmID                  = (int) $item['nmID'];
                    $price_type            = $item['price_type'];
                    $pricing_modifier_type = $item['pricing_modifier_type'];
                    $this->log['nmID']     = $nmID;
                    $this->log['strategy'] = 'TIME';

                    $data['data']            = [];
                    $data['data'][0]['nmID'] = $nmID;

                    if ($item['strategy'] == 'TIME') {
                        $mode = $this->timeStrategy($item);
                        if ($mode === true) //если true то условий для продолжения нет(всё работает как нужно), выходим
                        {
                            continue;
                        }

                        // Счётчик считает кол-во попыток работать с одной номенклатурой.
                        // Не его основе мы отключаем работу после соотвествующего числа повторений
                        $item->repeats_counter++;

                        switch ($mode) {
                            case 'base_price':
                                if ($price_type == 'PRICE') {
                                    $data['data'][0]['price'] = (int) $item['base_value'];
                                } else if ($price_type == 'DISCOUNT') {
                                    $data['data'][0]['discount'] = (int) $item['base_discount'];
                                }
                                $price_change = $this->setPrice($apikey, $data, $item);
                                if ($price_change) {
                                    $item->active = 0;
                                    if ($price_type == 'PRICE') {
                                        $this->log['message'] = 'Выход из стратегии. Стоимость до скидки установлена - ' . $item['base_value'] . ' р.';
                                    } else if ($price_type == 'DISCOUNT') {
                                        $this->log['message'] = 'Выход из стратегии. Скидка товара установлена - ' . $item['base_discount'] . '%';
                                    }

                                    $this->log['type'] = 'success';
                                }

                                break;
                            case 'new_price':
                                $base_values = $this->setBaseValues($cabinet->apikey, $nmID);
                                // Если получили текущую цену и скидку и сохранили в базе
                                if ($base_values) {
                                    if ($price_type == 'PRICE') {
                                        $price = $this->active_period['value'];
                                        if ($pricing_modifier_type == 'PROCENT') {
                                            $price = $item['base_value'] + $item['base_value'] / 100 * $this->active_period['value'];
                                        }
                                        $data['data'][0]['price'] = (int) $price;
                                        // Если новая цена равна той, что уже стоит у товара
                                        // WB вернёт ошибку. Так делать не нужно
                                        if ($price == $item['base_value']) {
                                            $this->log['message'] = 'Цена товара при входе в стратегию и текущая цена товара - равны. Ничего не делаем.';
                                            $this->log['type'] = 'info';
                                            if ($item->repeats_counter >= $this->errors_limit) {
                                                $this->log['message'] = 'Мы отключим номенклатуру. Обратите внимание на информационные сообщения ниже, и исправьте ситуацию.';
                                                $item->status         = 0;
                                            }
                                        } else {
                                            $price_change = $this->setPrice($apikey, $data, $item);
                                            if ($price_change) {
                                                $item->active         = 1;
                                                $this->log['message'] = 'Вход в стратегию. Стоимость товара - ' . $price . ' р.';
                                                $this->log['type']    = 'success';
                                            }
                                        }
                                    } else if ($price_type == 'DISCOUNT') {
                                        $discount                    = $this->active_period['value'];
                                        $data['data'][0]['discount'] = (int) $discount;
                                        // Если новая цена равна той, что уже стоит у товара
                                        // WB вернёт ошибку. Так делать не нужно
                                        if ((int) $discount == $item['base_discount']) {
                                            $this->log['message'] = 'Скидка товара при входе в стратегию и текущая скидка товара - равны. Ничего не делаем.';
                                            $this->log['type']    = 'info';
                                            if ($item->repeats_counter >= $this->errors_limit) {
                                                $this->log['message'] = 'Мы отключим номенклатуру. Обратите внимание на информационные сообщения ниже, и исправьте ситуацию.';
                                                $item->status         = 0;
                                            }
                                        } else {
                                            $price_change = $this->setPrice($apikey, $data, $item);
                                            if ($price_change) {
                                                $item->active         = 1;
                                                $this->log['message'] = 'Вход в стратегию. Cкидка товара - ' . $discount . '%';
                                                $this->log['type']    = 'success';
                                            }
                                        }
                                    } // if $price_type == 'DISCOUNT'
                                } // if $base_values

                                break;
                            default:
                                return 2; //Стратегия времени проверена, выходим ничего не делаем

                        }
                    }

                    if ($this->log['type'] == 'success') {
                        // Если success = true, отчистим счётчик попыток
                        $item->repeats_counter = 0;
                    }
                    $item->save();

                    if ($this->log['message'] != '') {
                        RepricerLogs::create($this->log);
                    }
                }
            }
        }
    }

    /**
     * Определяет режим для тайм-стратегии.
     *
     * @param  \App\Models\Subscribers\Wb\Repricer\RepricerSettings|array  $record
     * @return string|bool  'new_price' | 'base_price' | true
     */
    private function timeStrategy($record)
    {
        // Сбрасываем сохранённый активный период перед новой проверкой
        $this->active_period = null;

        if (!$record) {
            return true;
        }

        // Приведём данные
        if ($record instanceof \App\Models\Subscribers\Wb\Repricer\RepricerSettings) {
            $active  = (int) $record->active;
            $enabled = (int) $record->status; // статус (работаем/не работаем)
            $terms   = $record->terms;
        } elseif (is_array($record)) {
            $active  = (int) ($record['active'] ?? 0);
            $enabled = (int) ($record['status'] ?? 0);
            $terms   = $record['terms'] ?? [];
        } else {
            return true;
        }

        // Если стратегия выключена пользователем — ничего не делаем
        if (!$enabled) {
            return true;
        }

        // Приводим terms к массиву периодов
        if (isset($terms['start'], $terms['end'])) {
            $periods = [$terms]; // старый формат
        } elseif (is_array($terms)) {
            $periods = $terms;
        } else {
            return true;
        }

        $now        = now();
        $nowMinutes = $now->hour * 60 + $now->minute;

        $foundActivePeriod = null;

        foreach ($periods as $p) {
            if (!is_array($p) || !isset($p['start'], $p['end'])) {
                continue;
            }
            $startStr = $p['start'];
            $endStr   = $p['end'];

            if (!preg_match('/^\d{2}:\d{2}$/', $startStr) || !preg_match('/^\d{2}:\d{2}$/', $endStr)) {
                continue;
            }

            [$sh, $sm] = array_map('intval', explode(':', $startStr));
            [$eh, $em] = array_map('intval', explode(':', $endStr));
            $s = $sh * 60 + $sm;
            $e = $eh * 60 + $em;

            // Нулевой интервал игнорируем
            if ($s === $e) {
                continue;
            }

            $isActiveNow = ($s < $e)
                ? ($nowMinutes >= $s && $nowMinutes < $e)             // обычный интервал
                : ($nowMinutes >= $s || $nowMinutes < $e);            // через полночь

            if ($isActiveNow) {
                $foundActivePeriod = $p;
                break; // берём первый совпавший
            }
        }

        // Если нашли активный период – сохраняем
        if ($foundActivePeriod) {
            $this->active_period = $foundActivePeriod;

            if (!$active) {
                // Нужно включить стратегию
                return 'new_price';
            }
            // Уже активна — оставляем как есть
            return true;
        }

        // Активного периода нет, но стратегия была активна -> вернуть базовую цену
        if ($active) {
            return 'base_price';
        }

        return true;
    }


    private function setPrice($apikey, $data, $item)
    {
        Log::channel('wb_api_response')->info(json_encode($data));
        sleep(1); //Превышен лимит запросов. (Максимум 10 запросов за 6 секунд для всех методов категории Цены и скидки на один аккаунт продавца)
        $resp = $this->parseApiResponse($this->apiSetPrice($apikey, $data));

        if (! $resp['success']) {
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 1.';
            $this->log['type']    = 'error';

            // Обработаем ошибки
            if ($resp['code'] == 400) {
                if ($resp['data']['error']) {
                    $errorText = $resp['data']['errorText'] ?? '';
                    // Если ошибка "No goods for process" - предположим, что у нас уже стоит верная цена, и мы, пытаемся поставить такую-же.
                    Log::channel('wb_api_response')->info('Текст ошибки: ' . $errorText);

                    if (stripos($errorText, 'already set') !== false) {
                        $this->log['message'] = 'Устанавливаемая цена (скидка) уже применена на WB или карточка не имеет цены. Отключаем работу с номенклатурой.';
                        $item->active         = 0;
                        $item->status         = 0;
                        $item->save();
                    }

                    // Если мы много раз пытаемся войти/выйти из стратегии, вероятно, какая-то ошибка.
                    // Отключим номенклатуру.
                    if ($item->repeats_counter >= $this->errors_limit) {
                        $this->log['message'] = 'Останавливаем работу с номенклатурой после превышения лимита ошибок. Проверьте настройки номенклатуры. Проверьте цену/скидку номенклатуры на WildBerries.';
                        $item->active         = 0;
                        $item->status         = 0;
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
        if (! isset($resp['data']['data']['id'])) {
            return false;
        }

        sleep(1);
        $price_change = $this->checkPriceChange($apikey, $resp['data']['data']['id']);

        if (! $price_change) {
            sleep(1);
            $price_change = $this->checkPriceChange($apikey, $resp['data']['data']['id']);
            if (! $price_change) {
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
    private function checkPriceChange($apikey, $uploadID)
    {
        $resp = $this->parseApiResponse($this->apiGetPriceChangeStatus($apikey, $uploadID));

        if (! $resp['success']) {
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 2.';
            $this->log['type']    = 'error';
            return false;
        }

        if (isset($resp['data']['data']['status'])) {
            $status = $resp['data']['data']['status'];
        } else {
            // Тут была замечена проблема, что $resp['data']['data']['status'] нет, но код 200 и цена изменена.
            // поэтому, если тут код 200, попробуем проверить ещё раз статус выгрузки
            if ($resp['code'] == 200) {
                sleep(1);
                return $this->checkPriceChange($apikey, $uploadID);
            }
            $this->log['message'] = 'Код: ' . $resp['code'] . ' от wildberries. Не удалось сменить стоимость. Тип 3.';
            $this->log['type']    = 'error';
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
            $this->log['type']    = 'error';
            return false;
        }

        return true;
    }

    private function setBaseValues($apikey, $nmID)
    {

        $params = [
            'limit'      => 1,
            'filterNmID' => $nmID,
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($apikey, $params));

        if (! $response['success'] && isset($response['code'])) {
            $this->log['nmID'] = $nmID;
            $this->log['type'] = 'error';
            if ($response['code'] == 401) {
                $this->log['message'] = 'Не верный ключ API';
            }
            if ($response['code'] != 200) {
                $this->log['message'] = 'Не удалось получить текущие цены и скидки';
            }
            return false;
        }

        $card = $response['data']['data']["listGoods"][0];

        RepricerSettings::where('nmID', $nmID)->update([
            'base_value'    => $card["sizes"][0]["price"],
            'base_discount' => $card["discount"],
        ]);

        return true;
    }
}
