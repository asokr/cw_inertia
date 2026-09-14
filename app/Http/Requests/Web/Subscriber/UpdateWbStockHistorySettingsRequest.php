<?php

namespace App\Http\Requests\Web\Subscriber;

use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWbStockHistorySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'retention_days' => [
                'required',
                'integer',
                'min:'.WbStockHistorySetting::MIN_RETENTION_DAYS,
                'max:'.WbStockHistorySetting::MAX_RETENTION_DAYS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retention_days.required' => 'Укажите, сколько дней хранить историю.',
            'retention_days.integer' => 'Срок хранения должен быть числом.',
            'retention_days.min' => 'Минимум '.WbStockHistorySetting::MIN_RETENTION_DAYS.' дней.',
            'retention_days.max' => 'Максимум полгода ('.WbStockHistorySetting::MAX_RETENTION_DAYS.' дней).',
        ];
    }
}
