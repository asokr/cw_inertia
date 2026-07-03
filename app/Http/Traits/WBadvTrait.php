<?php

namespace App\Http\Traits;

use App\Http\Traits\GuzzleTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait WBadvTrait
{
    // Методы актуальны на 01,11,2024
    use GuzzleTrait;

    /*
    /* РЕПРАЙСЕР
    /*
    */

    // Изменение цены
    // $data (array) - данные для изменения
    // $data[ nmID,  price, discount ]
    public function apiSetPrice($apiKey, $data)
    {

        $url = 'https://discounts-prices-api.wildberries.ru/api/v2/upload/task';

        $result = $this->postRequest($url, $apiKey, $data, 'apiSetPrice');

        return ['data' => $result, 'function' => 'apiSetPrice'];
    }

    // Статус задания на изменение цены
    // $uploadID (int) - ID загрузки цен WB
    public function apiGetPriceChangeStatus($apiKey, $uploadID)
    {
        $params = [
            'uploadID' => $uploadID
        ];

        $url = 'https://discounts-prices-api.wildberries.ru/api/v2/history/tasks';

        $result = $this->getRequest($url, $apiKey, $params);

        return ['data' => $result, 'function' => 'apiGetPriceChangeStatus'];
    }

    // Получаем NMid и цену из кабинета
    // Возвращает информацию о товаре по его артикулу.
    // Чтобы получить информацию обо всех товарах, оставьте артикул пустым
    // $limit (int) - Сколько элементов вывести на одной странице (пагинация). Максимум 1 000 элементов
    // $offset (int) - После какого элемента выдавать данные
    // $filterNmID (int) - Артикул WB, по которому искать товар

    public function apiGetPrices($apiKey, $params)
    {

        $url = 'https://discounts-prices-api.wildberries.ru/api/v2/list/goods/filter';

        $result = $this->getRequest($url, $apiKey, $params);

        return ['data' => $result, 'function' => 'apiGetPrices'];
    }

    // РЕПРАЙСЕР. Получим общие остатки
    // public function apiGetStockData($apiKey, $nm)
    // {
    //     $end = Carbon::now('Europe/Moscow')->subMinutes(2);
    //     $begin = (clone $end)->subMinutes(10);

    //     $params = [
    //         'selectedPeriod' => [
    //             'begin' => $begin->format('Y-m-d H:i:s'),
    //             'end'   => $end->format('Y-m-d H:i:s'),
    //         ],
    //         'nmIds' => [(int) $nm]
    //     ];

    //     $url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products';

    //     $result = $this->postRequest($url, $apiKey, $params);

    //     return ['data' => $result, 'function' => 'apiGetStockData'];
    // }

    // РЕПРАЙСЕР. Получим остатки по размерам
    public function apiGetStockDataBySize($apiKey, bool $fetchAll = true)
    {
        $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/stocks';
        $function = 'apiGetStockDataBySize';

        $dateFrom = Carbon::now('Europe/Moscow')->subMonths(12)->format('Y-m-d');
        $allStocks = [];
        $visitedDateMarkers = [];
        $nextDateFrom = $dateFrom;
        $lastResponse = null;

        while (true) {
            $params = [
                'dateFrom' => $nextDateFrom,
            ];

            $response = $this->getRequest($url, $apiKey, $params);

            if (!$response) {
                return ['data' => $response, 'function' => $function];
            }

            $lastResponse = $response;

            $code = $response['code'] ?? null;

            if ($code !== 200) {
                if ($code === 204) {
                    $response['response'] = json_encode($allStocks, JSON_UNESCAPED_UNICODE);
                    return ['data' => $response, 'function' => $function];
                }

                return ['data' => $response, 'function' => $function];
            }

            $decoded = json_decode($response['response'] ?? '', true);

            if (!is_array($decoded)) {
                return ['data' => $response, 'function' => $function];
            }

            if (empty($decoded)) {
                $lastResponse['response'] = json_encode($allStocks, JSON_UNESCAPED_UNICODE);
                return ['data' => $lastResponse, 'function' => $function];
            }

            $allStocks = array_merge($allStocks, $decoded);

            if (! $fetchAll) {
                $response['response'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                return ['data' => $response, 'function' => $function];
            }

            $lastRow = end($decoded);
            $nextMarker = $lastRow['lastChangeDate'] ?? null;

            if (!$nextMarker || in_array($nextMarker, $visitedDateMarkers, true)) {
                $lastResponse['response'] = json_encode($allStocks, JSON_UNESCAPED_UNICODE);
                return ['data' => $lastResponse, 'function' => $function];
            }

            $visitedDateMarkers[] = $nextMarker;
            $nextDateFrom = $nextMarker;

            sleep(61); // API allows only one request per minute, so we wait before fetching the next batch
        }
    }

    // Остатки на складах: запуск формирования отчета
    public function apiCreateWarehouseRemainsReport($apiKey, $groupByNm = true, $groupBySize = true, $groupByBarcode = true)
    {
        $params = [];

        $params['groupByNm'] = $groupByNm;
        $params['groupBySize'] = $groupBySize;
        $params['groupByBarcode'] = $groupByBarcode;

        $url = 'https://seller-analytics-api.wildberries.ru/api/v1/warehouse_remains';

        $result = $this->getRequest($url, $apiKey, $params);

        return ['data' => $result, 'function' => 'apiCreateWarehouseRemainsReport'];
    }

    // Остатки на складах: статус формирования отчета
    public function apiGetWarehouseRemainsStatus($apiKey, $taskId)
    {
        $url = 'https://seller-analytics-api.wildberries.ru/api/v1/warehouse_remains/tasks/' . rawurlencode($taskId) . '/status';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiGetWarehouseRemainsStatus'];
    }

    // Остатки на складах: загрузка готового отчета
    public function apiDownloadWarehouseRemainsReport($apiKey, $taskId)
    {
        $url = 'https://seller-analytics-api.wildberries.ru/api/v1/warehouse_remains/tasks/' . rawurlencode($taskId) . '/download';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiDownloadWarehouseRemainsReport'];
    }

    public function apiGetSellerWarehouses($apiKey)
    {
        $url = 'https://marketplace-api.wildberries.ru/api/v3/warehouses';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiGetSellerWarehouses'];
    }

    public function apiGetSellerWarehouseStocks($apiKey, int $warehouseId, array $chrtIds)
    {
        $url = 'https://marketplace-api.wildberries.ru/api/v3/stocks/' . $warehouseId;

        $normalizedChrtIds = array_values(array_filter(array_map(static function ($id) {
            $intId = (int) $id;
            return $intId > 0 ? $intId : null;
        }, array_unique($chrtIds)), static function ($id) {
            return is_int($id) && $id > 0;
        }));

        $payload = [
            'chrtIds' => $normalizedChrtIds,
        ];

        $result = $this->postRequest($url, $apiKey, $payload, 'apiGetSellerWarehouseStocks');

        return ['data' => $result, 'function' => 'apiGetSellerWarehouseStocks'];
    }

    private function formatWbLogContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                try {
                    $context[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                } catch (\JsonException $exception) {
                    $context[$key] = var_export($value, true);
                }
            }
        }

        return $context;
    }

    // Список наменклатур
    public function getAllCards($apiKey, $params, $cards = [])
    {

        $url = 'https://content-api.wildberries.ru/content/v2/get/cards/list?locale=ru';

        $result = $this->postRequest($url, $apiKey, $params);

        if ($result) {
            if ($result["code"] != 200) {
                return $result;
            }
            $data = json_decode($result["response"], true);
            array_push($cards, ...$data["cards"]);

            if ($data["cursor"]["total"] >= $params["settings"]["cursor"]["limit"]) {

                $params["settings"]["cursor"]["updatedAt"] = $data["cursor"]["updatedAt"];
                $params["settings"]["cursor"]["nmID"] = $data["cursor"]["nmID"];

                $cards = $this->getAllCards($apiKey, $params, $cards);
            }

            return $cards;
        } else {
            return false;
        }
    }


    /*
    /* РЕПРАЙСЕР КОНЕЦ
    /*
    */


    /*
    * Получение информации о продавце
    */

    public function apiGetSellerInfo($apiKey)
    {
        $url = 'https://common-api.wildberries.ru/api/v1/seller-info';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiGetSellerInfo'];
    }

    public function parseApiResponse($resp)
    {
        if (!$resp['data']) {
            $success = false;
            $code = 503;
            $data = 'Ошибка доступа к API. Функция: ' . $resp['function'];
        } else {
            switch ($resp['data']["code"]) {
                case 200:
                    $success = true;
                    $code = 200;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 400:
                    $success = false;
                    $code = 400;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 401:
                    $success = false;
                    $code = 401;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 422:
                    $success = false;
                    $code = 422;
                    $data = $resp['data']["response"];
                    break;
                case 429:
                    $success = false;
                    $code = 429;
                    $data = 'Превышен лимит запросов. Функция: ' . $resp['function'];
                    break;
                case 204:
                    $success = true;
                    $code = 204;
                    $data = [];
                    break;
                default:
                    $success = false;
                    $code = 503;
                    $data = 'Неизвестная ошибка API';
                    break;
            }
        }

        if (!$success) {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            Log::channel('wb_api_response')->info('Код ответа: ' . $code . '. Сообщение: ' . $message);
        }

        return ['success' => $success, 'code' => $code, 'data' => $data];
    }
}
