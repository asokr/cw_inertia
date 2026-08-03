<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class ModifyAbCampaignNmsRequest extends FormRequest
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
            'experiment_id' => ['required', 'integer', 'min:1'],
            'bind' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'experiment_id.required' => 'Не выбран эксперимент.',
        ];
    }
}
