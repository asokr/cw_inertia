<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SupportMessageRequest;
use App\Jobs\SendContactFormEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class SupportMessageController extends Controller
{
    public function store(SupportMessageRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $data = [
            'Имя' => $validated['name'],
            'Телефон' => $validated['phone'],
            'Сообщение' => $validated['message'],
            'Источник' => $validated['source'] ?? 'support_form',
        ];

        if ($request->user()) {
            $data['Email аккаунта'] = $request->user()->email;
        }

        if (! empty($validated['context_email'])) {
            $data['Email из формы'] = $validated['context_email'];
        }

        try {
            SendContactFormEmail::dispatchSync(
                'support@cwplatform.ru',
                'Обращение в поддержку CW Platform',
                $data,
            );
        } catch (\Throwable $exception) {
            Log::error('Support message send failed', [
                'error' => $exception->getMessage(),
                'source' => $validated['source'] ?? null,
            ]);

            return back()->with('error', 'Не удалось отправить сообщение. Попробуйте позже.');
        }

        return back()->with('success', 'Сообщение отправлено. Мы ответим в ближайшее время.');
    }
}