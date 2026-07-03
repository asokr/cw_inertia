<?php

namespace App\Http\Traits;

use App\Http\Traits\GuzzleTrait;

trait WBstatTrait
{

  use GuzzleTrait;

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1incomes/get
  public function apiContentAnalytics($apiKey, $data)
  {

    $url = 'https://suppliers-api.wb.ru/content/v1/analytics/nm-report/detail';

    $result = $this->postRequest($url, $apiKey, $data);

    return $result;

  }

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1incomes/get
  public function apiSupplierIncomes($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/incomes';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1stocks/get
  public function apiSupplierStocks($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/stocks';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

  // https://statistics-api.wildberries.ru/api/v1/supplier/orders
  public function apiSupplierOrders($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/orders';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1sales/get
  public function apiSupplierSales($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/sales';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1reportDetailByPeriod/get
  public function apiSupplierReportDetailByPeriod($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/reportDetailByPeriod';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

  // https://openapi.wb.ru/#tag/Statistika/paths/~1api~1v1~1supplier~1excise-goods/get
  public function apiSupplierExciseGoods($apiKey, $data)
  {

    $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/excise-goods';

    $result = $this->getRequest($url, $apiKey, $data);

    return $result;

  }

}
