<?php

namespace App\Http\Requests\Web\Subscriber;

/**
 * Настройки Ozon A/B: длительность круга не меньше 30 минут (статистика показов не успевает за меньшее окно).
 */
class UpdateOzAbExperimentSettingsRequest extends UpdateAbExperimentSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['round_minutes'] = ['required', 'integer', 'min:30', 'max:1440'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = parent::messages();
        $messages['round_minutes.min'] = 'Минимум 30 минут.';

        return $messages;
    }
}
