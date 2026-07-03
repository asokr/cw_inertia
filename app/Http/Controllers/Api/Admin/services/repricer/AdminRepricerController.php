<?php

namespace App\Http\Controllers\Api\Admin\services\repricer;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;

class AdminRepricerController extends Controller
{
    public function getLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'integer',
            'rows' => 'integer',
            'nmID' => 'integer'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->cabinet_id) {
            $cabinet = RepricerCabinets::find($request->cabinet_id);
            if (!$cabinet)
                return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

            $data = RepricerLogs::select([
                'nmID',
                'message',
                'type',
                'created_at'
            ])->where('cabinet_id', $request->cabinet_id)->orderBy('id', 'desc')->paginate($request->rows);
        } else if ($request->nmID) {
            $data = RepricerLogs::select([
                'nmID',
                'message',
                'type',
                'created_at'
            ])->where('nmID', $request->nmID)->orderBy('id', 'desc')->paginate($request->rows);
        } else {
            $data = RepricerLogs::select([
                'nmID',
                'message',
                'type',
                'created_at'
            ])->orderBy('id', 'desc')->paginate($request->rows);
        }




        return response()->json(["success" => true, "messages" => ["Логи работы репрайсера"], "data" => $data], 200);
    }

    public function getNmIds(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'integer',
            'rows' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->cabinet_id) {
            $cabinet = RepricerCabinets::find($request->cabinet_id);
            if (!$cabinet)
                return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

            $data = RepricerSettings::where('cabinet_id', $request->cabinet_id)->with([
                'cabinet' => function ($query) {
                    $query->select('id', 'user_id', 'name')->with([
                        'user' => function ($query) {
                            $query->select('id', 'name', 'email')->with([
                                'subscriber' => function ($query) {
                                    $query->select('id', 'user_id');
                                }
                            ]);
                        }
                    ]);
                }
            ])->orderBy('id', 'desc')->paginate($request->rows);
        } else {
            $data = RepricerSettings::with([
                'cabinet' => function ($query) {
                    $query->select('id', 'user_id', 'name')->with([
                        'user' => function ($query) {
                            $query->select('id', 'name', 'email')->with([
                                'subscriber' => function ($query) {
                                    $query->select('id', 'user_id');
                                }
                            ]);
                        }
                    ]);
                }
            ])->with(['logs' => function ($query) {
                $query->select('nmID', 'type', 'message', 'created_at')->limit(50)->orderBy('created_at', 'desc');
            }])
                ->orderBy('id', 'desc')->paginate($request->rows);
        }

        return response()->json(["success" => true, "messages" => ["Номенклатуры получены"], "data" => $data], 200);
    }

    public function getCabinets(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $data = RepricerCabinets::select([
            'id',
            'user_id',
            'name',
            'created_at'
        ])->with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email')->with([
                    'subscriber' => function ($query) {
                        $query->select('id', 'user_id');
                    }
                ]);
            }
        ])->orderBy('id', 'desc')->paginate($request->rows);

        return response()->json(["success" => true, "messages" => ["Кабинеты получены"], "data" => $data], 200);
    }
}
