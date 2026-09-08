<?php

namespace App\Http\Requests\Web\Subscriber;

/**
 * Настройки WB A/B: ставка обязательна и хранится в поле cpm.
 */
class UpdateWbAbExperimentSettingsRequest extends UpdateAbExperimentSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['cpm'] = ['required', 'integer', 'min:1', 'max:50000'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = parent::messages();
        $messages['cpm.required'] = 'Укажите ставку.';
        $messages['cpm.integer'] = 'Ставка должна быть целым числом.';
        $messages['cpm.min'] = 'Минимум 1 ₽.';
        $messages['cpm.max'] = 'Максимум 50 000 ₽.';

        return $messages;
    }
}
