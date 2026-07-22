<?php

namespace App\Http\Traits;

use App\Support\Wb\WbBasketHost;
use GuzzleHttp;
use Illuminate\Support\Facades\Log;

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
        $sku = strval($productId);
        $vol = WbBasketHost::vol($productId);
        $part = WbBasketHost::part($productId);
        $bNum = $this->basketNumber($vol);
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
        $sku = strval($productId);
        $vol = WbBasketHost::vol($productId);
        $part = WbBasketHost::part($productId);
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
        return WbBasketHost::number((int) $t);
    }

    private function getUserAgent()
    {
        $user_agents = config('wbConstants.USERAGENT');

        return $user_agents;
    }
}
