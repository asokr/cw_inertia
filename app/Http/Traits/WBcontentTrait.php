<?php

namespace App\Http\Traits;

use App\Http\Traits\GuzzleTrait;

trait WBcontentTrait
{

  use GuzzleTrait;

  // https://openapi.wb.ru/#tag/Kontent-Analitika/paths/~1content~1v1~1analytics~1nm-report~1detail/post
  public function apiContentAnalytics($apiKey, $data)
  {

    $url = 'https://suppliers-api.wb.ru/content/v1/analytics/nm-report/detail';

    $result = $this->postRequest($url, $apiKey, $data);

    return $result;

  }

}
