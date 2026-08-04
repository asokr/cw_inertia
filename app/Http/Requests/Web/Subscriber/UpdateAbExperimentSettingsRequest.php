<?php

namespace App\Http\Requests\Web\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAbExperimentSettingsRequest extends FormRequest
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
            'impressions_per_photo' => ['required', 'integer', 'min:1000', 'max:50000000'],
            'impressions_per_round' => ['required', 'integer', 'min:100', 'max:50000000'],
            'round_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            // Lower bound is 1 so CPC (price per click) can pass FormRequest;
            // payment-type-specific min (CPM ≥ 50 / CPC ≥ 1) is enforced in the service.
            'cpm' => ['required', 'integer', 'min:1', 'max:50000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'impressions_per_photo.required' => 'Укажите число показов на одно фото.',
            'impressions_per_photo.min' => 'Минимум 1 000 показов на одно фото.',
            'impressions_per_photo.max' => 'Максимум 50 000 000 показов на одно фото.',
            'impressions_per_round.required' => 'Укажите показы за круг.',
            'impressions_per_round.min' => 'Минимум 100 показов за круг.',
            'round_minutes.required' => 'Укажите длительность круга.',
            'round_minutes.min' => 'Минимум 5 минут.',
            'round_minutes.max' => 'Максимум 1440 минут (сутки).',
            'cpm.required' => 'Укажите ставку (CPM или CPC).',
            'cpm.min' => 'Ставка не меньше 1 ₽.',
            'cpm.max' => 'Ставка не больше 50 000 ₽.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $target = (int) $this->input('impressions_per_photo', 0);
            $perRound = (int) $this->input('impressions_per_round', 0);

            if ($target > 0 && $perRound > $target) {
                $validator->errors()->add(
                    'impressions_per_round',
                    'Показов за круг не может быть больше, чем всего показов на одно фото.',
                );
            }
        });
    }
}
