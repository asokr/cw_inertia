<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use App\Models\PaymentsTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class PaymentsController extends Controller
{
    public function payments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'required',
            'sortField' => '',
            'sortOrder' => ''
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $sortField = $request->has('sortField') ? $request->sortField : 'id';
        $sortOrder = $request->has('sortOrder') ? $request->sortOrder : '-1';

        $data = PaymentsTransaction::select([
            'id',
            'user_id',
            'amount',
            'description',
            'status',
            'system',
            'created_at'
        ])
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name', 'email')->with([
                        'subscriber' => function ($query) {
                            $query->select('id', 'user_id');
                        }
                    ]);
                }
            ])
            ->orderBy($sortField, $sortOrder == '1' ? 'asc' : 'desc')
            ->paginate($request->rows);

        if (!$data)
            return response()->json(["success" => false, "messages" => ["Нет оплат"]], 200);

        return response()->json(["success" => true, "messages" => ["История получена"], "data" => $data], 200);
    }
}
