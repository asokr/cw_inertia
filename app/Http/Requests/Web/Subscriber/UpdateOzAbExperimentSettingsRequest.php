<?php

namespace App\Http\Requests\Web\Subscriber;

/**
 * Настройки Ozon A/B: смена фото только по показам, минимум 5000 на вариант и 1000 за круг.
 */
class UpdateOzAbExperimentSettingsRequest extends UpdateAbExperimentSettingsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['impressions_per_photo'] = ['required', 'integer', 'min:5000', 'max:50000000'];
        $rules['impressions_per_round'] = ['required', 'integer', 'min:1000', 'max:50000000'];
        $rules['round_minutes'] = ['nullable', 'integer', 'min:1', 'max:1440'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = parent::messages();
        $messages['impressions_per_photo.min'] = 'Минимум 5 000 показов на одно фото.';
        $messages['impressions_per_round.min'] = 'Минимум 1 000 показов за круг.';

        return $messages;
    }
}
