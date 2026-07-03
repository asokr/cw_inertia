<?php

namespace App\Http\Traits;

use GuzzleHttp;
use Illuminate\Support\Facades\Log;
use App\Services\Wb\WbSearchService;

trait WBApiTrait
{
    function __construct()
    {
        $this->proxy = config('services.proxy');
    }

    private function wbPutRequest($url, $data = array(), $headers = array(), $jar = array())
    {
        if (!$jar)
            $jar = new \GuzzleHttp\Cookie\CookieJar;

        $headers = array_merge([
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
        ], $headers);

        $client = new GuzzleHttp\Client([
            'proxy' => $this->proxy,
            'headers' => $headers,
            'http_errors' => false,
            'cookies' => $jar
        ]);

        $response = $client->put($url, ['json' => $data]);

        if ($response->getStatusCode() == 200) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "cookies" => $jar
            );
        } else if ($response->getStatusCode() == 401) {
            // TODO log 401 error
            return 401;
        } else {
            return false;
        }
    }

    private function wbGetRequest($url, $data = array(), $headers = array(), $jar = array())
    {
        if (!$jar)
            $jar = new \GuzzleHttp\Cookie\CookieJar;

        $headers = array_merge([
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
        ], $headers);

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false,
            'cookies' => $jar
        ]);

        $response = $client->get($url, [
            'query' => $data
        ]);
        if ($response->getStatusCode() == 200) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "cookies" => $jar
            );
        } else if ($response->getStatusCode() == 401) {
            // TODO log 401 error
            return 401;
        } else {
            return false;
        }
    }

    private function wbPostRequest($url, $data = array(), $headers = array(), $jar = array())
    {
        if (!$jar)
            $jar = new \GuzzleHttp\Cookie\CookieJar;

        $headers = array_merge([
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
        ], $headers);

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false,
            'cookies' => $jar
        ]);

        $response = $client->post($url, [
            'json' => $data
        ]);

        if ($response->getStatusCode() == 200) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "cookies" => $jar
            );
        } else if ($response->getStatusCode() == 401) {
            // TODO log 401 error
            return 401;
        } else {
            return false;
        }
    }


    // РЕПРАЙСЕР.
    public function publicGetNmData($nm)
    {
        $params = [
            'appType' => 1,
            'dest' => -531264,
            'nm' => $nm,
        ];

        $url = 'https://card.wb.ru/cards/v4/detail';

        $result = $this->retryEmptyResponse(fn() => $this->wbGetRequest($url, $params));

        return $result ? (array) json_decode($result["response"]) : null;
    }

    public function publicGetCompetitors(int $nmId)
    {
        $service = app(WbSearchService::class);

        return $service->recommendations($nmId);
    }

    public function publicSearchCatalog(string $query)
    {
        Log::warning('publicSearchCatalog is deprecated. Use async flow with WbSearchService::dispatchSearch');

        return null;
    }

    public function wbSearchHealth(): ?array
    {
        $service = app(WbSearchService::class);

        return $service->health();
    }

    private function retryEmptyResponse(callable $callback)
    {
        $attempts = 0;

        while ($attempts < 3) {
            $attempts++;

            $response = $callback();
            if (is_array($response) && !empty($response['response'])) {
                return $response;
            }

            if ($attempts < 3) {
                sleep(1);
            }
        }

        return null;
    }

    /**
     * It makes a request to the server and gets the product data
     */
    private function productDataApi($productId)
    {
        $limits = [0, 0, 0, 0, 0, 0, 1, 2, 3, 4, 5, 6, 7, 8];

        $sku = strval($productId);

        $vol = strlen($sku) > 5 ? substr($sku, 0, $limits[strlen($sku)]) : 0;
        $part = substr($sku, 0, $limits[strlen($sku) + 2]);
        $bNum = $this->basketNumber($sku / 1e5);
        $URL = config('wbConstants.URLS.PRODUCT.CARD');
        $URL = sprintf($URL, $bNum, $vol, $part, $sku);

        $res = $this->wbGetRequest($URL);
        $res = isset($res["response"]) ? json_decode($res["response"]) : false;

        if ($res) {
            $result = (array) $res;

            // Возмём изображения товара
            $result['images'] = $this->getProductImages($result["media"]->photo_count, $productId);

            return $result;
        }

        return false;
    }

    private function productDetailsApi($productId)
    {

        $sku = strval($productId);

        $query = array(
            "appType" => config('wbConstants.APPTYPES.DESKTOP'),
            "dest" => config('wbConstants.DESTINATIONS.MOSCOW.ids'),
            "regions" => config('wbConstants.DESTINATIONS.MOSCOW.regions'),
            "stores" => config('wbConstants.STORES.UFO'),
            "locale" => config('wbConstants.LOCALES.RU'),
            "nm" => $sku
        );

        $res = $this->wbGetRequest(config('wbConstants.URLS.PRODUCT.DETAILS'), $query);
        $res = isset($res["response"]) ? json_decode($res["response"]) : false;

        if ($res) {
            $result = (array) $res->data->products[0];

            $result['images'] = $this->getProductImages($result["pics"], $productId);

            return $result;
        } else {
            return false;
        }
    }

    private function getProductImages($photo_count, $productId)
    {
        $limits = [0, 0, 0, 0, 0, 0, 1, 2, 3, 4, 5, 6, 7, 8];

        $sku = strval($productId);

        // $vol = strlen($sku) > 5 ? substr($sku, 0, $limits[strlen($sku)]) : 0;
        // $part = substr($sku, 0, $limits[strlen($sku) + 2]);
        $nm = intval($productId);
        $vol = intval($nm / 1e5);
        $part = intval($nm / 1e3);
        $bNum = $this->basketNumber($vol);

        $result = array();

        for ($i = 1; $i <= $photo_count; $i++) {
            $imageS = sprintf(config('wbConstants.URLS.IMAGES.SMALL'), $bNum, $vol, $part, $sku, $i);
            $imageB = sprintf(config('wbConstants.URLS.IMAGES.BIG'), $bNum, $vol, $part, $sku, $i);
            $result[] = array(
                'imageS' => $imageS,
                'imageB' => $imageB
            );
        }

        return $result;
    }

    private function basketNumber($t)
    {
        $t = (int) $t;

        $ranges = [
            143,
            287,
            431,
            719,
            1007,
            1061,
            1115,
            1169,
            1313,
            1601,
            1655,
            1919,
            2045,
            2189,
            2405,
            2621,
            2837,
            3053,
            3269,
            3485,
            3701,
            3917,
            4133,
            4349,
            4565,
            4877,
            5189,
            5501,
            5813,
            6125,
            6437,
            6749,
            7061,
            7373,
        ];

        foreach ($ranges as $index => $limit) {
            if ($t <= $limit) {
                return str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            }
        }

        return str_pad((string) (count($ranges) + 1), 2, '0', STR_PAD_LEFT);
    }

    private function getUserAgent()
    {
        $user_agents = config('wbConstants.USERAGENT');

        return $user_agents;
    }
}
