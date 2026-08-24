<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class StoreOzAbCampaignRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'cpm' => ['nullable', 'integer', 'min:1', 'max:50000'],
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
