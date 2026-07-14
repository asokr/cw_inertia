<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\SendContactFormEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactMessageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = [];

        if (auth()->check()) {
            $data['Имя и почта'] = auth()->user()->getFullName();
        }

        foreach ($request->all() as $key => $value) {
            if (! empty($value) && $key !== 'subject') {
                $data[ucfirst($key)] = $value;
            }
        }

        try {
            SendContactFormEmail::dispatchSync(
                'info@cwplatform.ru',
                $request->subject ?? 'Новая заявка с сайта cwplatform.ru',
                $data,
            );

            return response()->json([
                'success' => true,
                'messages' => ['Сообщение успешно отправлено'],
            ], 200);
        } catch (\Throwable $th) {
            Log::error('Ошибка при отправке почты', [
                'error' => $th->getMessage(),
                'data' => $data,
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Ошибка при отправке сообщения, попробуйте позже.'],
            ], 500);
        }
    }
}