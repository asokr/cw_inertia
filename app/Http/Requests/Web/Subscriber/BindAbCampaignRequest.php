<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class BindAbCampaignRequest extends FormRequest
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
            'advert_id' => ['required', 'integer', 'min:1'],
            'add_product' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'advert_id.required' => 'Не выбрана рекламная кампания.',
            'advert_id.integer' => 'Некорректный ID кампании.',
        ];
    }
}
