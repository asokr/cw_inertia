<?php

namespace App\Http\Controllers\Api\Subscriber;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use O21\LaravelWallet\Models\Transaction;

class WalletController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        // return response()->json(["success" => true, "messages" => ["Баланс получен"], "data" => $data], 200);
    }

    /**
     * Display history of transactions.
     */
    public function history()
    {

        $data = Transaction::select([
            'id', 'amount', 'status', 'meta', 'created_at'
        ])
            ->where('to_id', auth()->id())
            ->orderBy('id', 'desc')
            ->get();

        if (!$data)
            return response()->json(["success" => false, "messages" => ["Нет оплат"]], 200);

        return response()->json(["success" => true, "messages" => ["История получена"], "data" => $data], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
