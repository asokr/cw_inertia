<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class PrepareOzAbCampaignRequest extends FormRequest
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
