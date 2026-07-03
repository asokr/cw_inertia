<?php

namespace App\Http\Controllers\Api\Subscriber;

use Illuminate\Http\Request;
use App\Models\FullfilmentPrices;
use App\Http\Controllers\Controller;

class FullfilmentController extends Controller
{
    public function index()
    {
        $data = FullfilmentPrices::select([
            'id',
            'city',
            'warehouses',
            'marketplaces',
            'our_services',
            'services',
        ])->get();

        return response()->json(["success" => true, "messages" => ["Цены получены"], "data" => $data], 200);
    }
}
