<?php

namespace App\Http\Controllers\Api\Subscriber;

use Log;
use App\Models\User;
use Illuminate\Http\Request;
use App\Enums\PaymentStatusEnum;
use App\Services\PaymentService;
use App\Models\PaymentsTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use YooKassa\Model\Notification\NotificationCanceled;
use YooKassa\Model\Notification\NotificationEventType;
use YooKassa\Model\Notification\NotificationSucceeded;
use YooKassa\Model\Notification\NotificationWaitingForCapture;

class PaymentController extends Controller
{
    public function index() {}

    public function create(Request $request, PaymentService $service)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric',
        ], [
            'amount.numeric' => 'Сумма пополнения должна быть числом',
            'required' => 'Не указаны необходимые параметры'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $description = 'Пополнение баланса';

        $transaction = PaymentsTransaction::create([
            'user_id' => auth()->id(),
            'amount' => $request->amount,
            'description' => $description,
            'system' => 'YooKassa'
        ]);

        if ($transaction) {
            $link = $service->createPayment($request->amount, $description, [
                'transaction_id' => $transaction->id,
                'user_id' => auth()->id()
            ]);

            return response()->json(["success" => true, "messages" => ["Платёж создан"], "data" => $link], 200);
        }
    }

    public function callback(Request $request, PaymentService $service)
    {

        $source = file_get_contents('php://input');
        $requestBody = json_decode($source, true);

        Log::info('YooKassa event: ' . ($requestBody['event'] ?? 'none'));

        if (($requestBody['event'] === NotificationEventType::PAYMENT_SUCCEEDED)) {
            $notification = new NotificationSucceeded($requestBody);
        } elseif ($requestBody['event'] === NotificationEventType::PAYMENT_WAITING_FOR_CAPTURE) {
            $notification = new NotificationWaitingForCapture($requestBody);
        } else {
            $notification = new NotificationCanceled($requestBody);
        }

        $payment = $notification->getObject();

        if (isset($payment->status) && $payment->status === 'succeeded') {
            if ((bool) $payment->paid) {
                $metadata = (object)$payment->metadata;
                if (isset($metadata->transaction_id)) {
                    $transaction_id = (int) $metadata->transaction_id;
                    $transaction = PaymentsTransaction::find($transaction_id);
                    $transaction->system_id = $payment->id;
                    $transaction->status = PaymentStatusEnum::CONFIRMED;
                    $transaction->save();

                    $amount = (float)$payment->amount->value;
                    $user_id = (int) $metadata->user_id;
                    $user = User::find($user_id);
                    deposit($amount, 'RUB')->to($user)->overcharge()
                        ->meta([
                            'transaction_id' => $transaction_id,
                            'description' => $transaction->description,
                        ])
                        ->commit();
                }
            }
        } else if (isset($payment->status) && $payment->status === 'canceled') {
            $metadata = (object) $payment->metadata;
            if (isset($metadata->transaction_id)) {
                $transaction_id = (int) $metadata->transaction_id;
                $transaction = PaymentsTransaction::find($transaction_id);
                $transaction->system_id = $payment->id;
                $transaction->status = PaymentStatusEnum::CANCELED;
                $transaction->save();
            }
        } else {
            $metadata = (object) $payment->metadata;
            if (isset($metadata->transaction_id)) {
                $transaction_id = (int) $metadata->transaction_id;
                $transaction = PaymentsTransaction::find($transaction_id);
                $transaction->system_id = $payment->id;
                $transaction->status = PaymentStatusEnum::FAILED;
                $transaction->save();
            }
        }
    }

    /**
     * Display history of transactions.
     */
    public function history()
    {

        $data = PaymentsTransaction::select([
            'id',
            'amount',
            'description',
            'status',
            'system',
            'created_at'
        ])
            ->where('user_id', auth()->id())
            ->orderBy('id', 'desc')
            ->get();

        if (!$data)
            return response()->json(["success" => false, "messages" => ["Нет оплат"]], 200);

        return response()->json(["success" => true, "messages" => ["История получена"], "data" => $data], 200);
    }
}
