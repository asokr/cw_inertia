<?php

namespace App\Http\Requests\Web\Subscriber;

use App\Support\Wb\WbStockHistoryCalendar;
use Illuminate\Foundation\Http\FormRequest;

class LoadWbStockHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Для служебного refresh: клиент передаёт дату начала, конец всегда сегодня.
     * Первая загрузка дату не принимает.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = WbStockHistoryCalendar::todayDate();

        return [
            'from' => ['required', 'date', 'before_or_equal:'.$today],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'Укажите, с какой даты загрузить историю.',
            'from.date' => 'Укажите, с какой даты загрузить историю.',
            'from.before_or_equal' => 'Дата начала не может быть позже сегодняшнего дня.',
        ];
    }
}
